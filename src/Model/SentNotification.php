<?php

namespace SilverShop\NotificationLog\Model;

use SilverShop\Model\Order;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Security\Member;

/**
 * An immutable, append-only record that one email was handed to the mailer: who it went to, which
 * notification it was, when, and (optimistically in v1) whether it was accepted by the transport.
 *
 * Rows are written only in code — by the {@link \SilverShop\NotificationLog\Extension\NotificationLogMailerExtension}
 * capture hook — never created or edited by hand in the CMS. The factual record of *what was sent*
 * (NotificationKey, Category, SentAt, the Order link) is immutable; only the administrative /
 * data-protection fields (PII redaction, the Member link, a legal hold) may change afterwards, so an
 * Art. 17 erasure can pseudonymise a row without rewriting history.
 *
 * Mirrors the immutability + "created only in code" posture of silvershop/ledger's ShopPostingEntry.
 *
 * @property string  $Recipient
 * @property string  $RecipientName
 * @property string  $Subject
 * @property string  $NotificationKey
 * @property string  $NotificationLabel
 * @property string  $Category
 * @property string  $SentAt
 * @property bool    $Success
 * @property string  $Error
 * @property string  $MessageID
 * @property ?string $Body
 * @property string  $PseudonymisedAt
 * @property bool    $LegalHold
 * @property int     $MemberID
 * @property int     $OrderID
 * @method   Member  Member()
 * @method   Order   Order()
 */
class SentNotification extends DataObject
{
    private static string $table_name = 'SilverShop_SentNotification';

    /**
     * Baseline retention (days) — GDPR storage-limitation. A SiteConfig value overrides it per install.
     * 0 = unbounded (never pruned). Default 365.
     */
    private static int $keep_days = 365;

    /**
     * Split retention (days) by category. 0 = fall back to keep_days. Transactional is typically longer
     * (contract / legitimate interest), marketing shorter (consent).
     */
    private static int $keep_days_transactional = 0;

    private static int $keep_days_marketing = 0;

    /**
     * How long stored bodies are kept (days) — typically shorter than keep_days. 0 = as long as the row.
     */
    private static int $keep_body_days = 0;

    private static array $db = [
        'Recipient' => 'Varchar',
        'RecipientName' => 'Varchar',
        'Subject' => 'Varchar',
        'NotificationKey' => 'Varchar(100)',
        'NotificationLabel' => 'Varchar',
        'Category' => "Enum('Transactional,Marketing,Admin','Transactional')",
        'SentAt' => 'Datetime',
        'Success' => 'Boolean(1)',
        'Error' => 'Text',
        'MessageID' => 'Varchar',
        // Stored only when store_body is enabled (opt-in). Heavy; pruned first (keep_body_days).
        'Body' => 'HTMLText',
        // Set when the row's PII has been scrubbed/hashed (erasure or pseudonymise). Non-null = pseudonymised.
        'PseudonymisedAt' => 'Datetime',
        // Per-row guard: an erasure routine must not delete/scrub a row under legal hold (GDPR Art. 17(3)).
        'LegalHold' => 'Boolean(0)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'Order' => Order::class,
    ];

    /**
     * has_one foreign keys (MemberID, OrderID) are indexed automatically by the ORM. These add the
     * remaining single-column indexes plus the two composites the Order/Member tab queries use
     * (filter by FK, sort by SentAt DESC).
     */
    private static array $indexes = [
        'SentAt' => true,
        'NotificationKey' => true,
        'Success' => true,
        'Category' => true,
        'MemberSentAt' => ['columns' => ['MemberID', 'SentAt']],
        'OrderSentAt' => ['columns' => ['OrderID', 'SentAt']],
    ];

    private static string $default_sort = '"SentAt" DESC, "ID" DESC';

    private static array $summary_fields = [
        'SentAt.Nice' => 'When',
        'NotificationLabel' => 'Notification',
        'CategoryLabel' => 'Category',
        'Recipient' => 'Recipient',
        'Order.Reference' => 'Order',
        'StatusLabel' => 'Status',
    ];

    private static array $searchable_fields = [
        'NotificationKey',
        'Category',
        'Recipient',
        'Order.Reference',
    ];

    public function summaryFields(): array
    {
        return [
            'SentAt.Nice' => _t(self::class . '.col_SentAt', 'When'),
            'NotificationLabel' => _t(self::class . '.col_Notification', 'Notification'),
            'CategoryLabel' => _t(self::class . '.col_Category', 'Category'),
            'Recipient' => _t(self::class . '.col_Recipient', 'Recipient'),
            'Order.Reference' => _t(self::class . '.col_Order', 'Order'),
            'StatusLabel' => _t(self::class . '.col_Status', 'Status'),
        ];
    }

    public function getCategoryLabel(): string
    {
        return _t(self::class . '.CATEGORY_' . $this->Category, (string) $this->Category);
    }

    public function getStatusLabel(): string
    {
        if ($this->PseudonymisedAt) {
            return _t(self::class . '.STATUS_Pseudonymised', 'Pseudonymised');
        }
        return $this->Success
            ? _t(self::class . '.STATUS_Sent', 'Sent')
            : _t(self::class . '.STATUS_Failed', 'Failed');
    }

    /**
     * Resolve the retention window (in days) for a given category: the split transactional/marketing
     * values when set, otherwise the baseline keep_days. A SiteConfig value (> 0) takes precedence over
     * YAML config, so a shop can set its own policy without a deploy. 0 / null = unbounded (never pruned).
     */
    public static function keepDaysForCategory(string $category): int
    {
        $baseline = self::resolveSetting('keep_days', 'NotificationLogKeepDays');

        $key = match ($category) {
            'Marketing' => ['keep_days_marketing', 'NotificationLogKeepDaysMarketing'],
            'Transactional' => ['keep_days_transactional', 'NotificationLogKeepDaysTransactional'],
            default => null,
        };

        if ($key === null) {
            return $baseline;
        }

        $specific = self::resolveSetting($key[0], $key[1]);

        return $specific > 0 ? $specific : $baseline;
    }

    /**
     * Resolve the body-retention window (days). Bodies are pruned before the metadata row, so this is
     * typically shorter than keep_days. 0 / null = bodies live as long as the row.
     */
    public static function keepBodyDays(): int
    {
        return self::resolveSetting('keep_body_days', 'NotificationLogKeepBodyDays');
    }

    /**
     * SiteConfig override (> 0) wins over the YAML config value; both default to 0 (unbounded) when unset.
     */
    protected static function resolveSetting(string $configKey, string $siteConfigField): int
    {
        $siteConfig = SiteConfig::current_site_config();
        if ((int) $siteConfig->{$siteConfigField} > 0) {
            return (int) $siteConfig->{$siteConfigField};
        }

        return (int) self::config()->get($configKey);
    }

    /**
     * Append-only: once persisted, the factual record of what was sent cannot change. The only writes
     * allowed on an existing row are the administrative / data-protection fields (PII redaction for
     * erasure, the Member link, and the legal-hold flag) — never NotificationKey/Category/SentAt/Order.
     */
    protected function onBeforeWrite(): void
    {
        if ($this->isInDB() && !$this->isAdministrativeWrite()) {
            throw ValidationException::create(_t(
                self::class . '.IMMUTABLE',
                'Sent-notification records are immutable and cannot be changed.'
            ));
        }

        parent::onBeforeWrite();
    }

    /**
     * True when this write only touches the administrative fields (redaction/pseudonymise, the Member
     * FK, the legal-hold flag). A no-op write (e.g. forceWrite with no changes) is allowed too.
     */
    private function isAdministrativeWrite(): bool
    {
        $changed = $this->getChangedFields(true, self::CHANGE_VALUE);
        unset($changed['LastEdited']);
        if ($changed === []) {
            return true;
        }

        $allowed = ['Recipient', 'RecipientName', 'Subject', 'Body', 'MemberID', 'PseudonymisedAt', 'LegalHold'];

        return array_diff(array_keys($changed), $allowed) === [];
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * Rows are only ever created in code (by the mailer capture hook), never by hand in the CMS.
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
