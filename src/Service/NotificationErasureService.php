<?php

namespace SilverShop\NotificationLog\Service;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

/**
 * Implements the right-to-erasure (GDPR Art. 17) and right-of-access/portability (Art. 15 / 20) surface
 * for the notification log. A central "forget / export this customer" flow (e.g. silvershop-suite/cookieconsent
 * or a Member-delete hook) calls erase()/export() so the log is swept too instead of silently defeating it.
 *
 * Default erasure = pseudonymise (Art. 4(5)): keep the non-identifying skeleton (which notification, when,
 * category, success) for counts/debugging but strip the PII. Hard-delete is available by config. Rows under
 * a legal hold (Art. 17(3)) are never touched.
 */
class NotificationErasureService
{
    use Injectable;
    use Configurable;

    /**
     * 'pseudonymise' (default) scrubs/hashes the PII and keeps the row; 'delete' removes it entirely.
     */
    private static string $erasure_mode = 'pseudonymise';

    /**
     * Optional salt for the one-way recipient hash, so a subject's pseudonymised rows can still be
     * correlated without being re-identifiable. Empty = unsalted sha256.
     */
    private static string $pseudonymise_salt = '';

    /**
     * Erase every log row belonging to a data subject, matched by Member link and/or raw recipient
     * address (to catch un-attributed rows that matched no Member). Honours the per-row legal hold.
     *
     * @return int number of rows erased (pseudonymised or deleted)
     */
    public function erase(string $email, ?Member $member = null): int
    {
        $mode = (string) static::config()->get('erasure_mode');
        $count = 0;

        /** @var SentNotification $row */
        foreach ($this->rowsFor($email, $member) as $row) {
            if ($row->LegalHold) {
                continue;
            }

            if ($mode === 'delete') {
                $row->delete();
            } else {
                $this->pseudonymise($row);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Scrub a single row's PII in place and stamp PseudonymisedAt. The recipient becomes a one-way hash
     * (correlatable, not re-identifiable); name and subject are redacted; any stored body is dropped; the
     * Member link is nulled. Leaves the non-PII facts (key/category/when/success) intact.
     */
    public function pseudonymise(SentNotification $row): void
    {
        $row->Recipient = $this->hash($row->Recipient);
        $row->RecipientName = '';
        $row->Subject = _t(self::class . '.REDACTED', '[redacted]');
        $row->Body = null;
        $row->MemberID = 0;
        $row->PseudonymisedAt = DBDatetime::now()->getValue();
        $row->write();
    }

    /**
     * Machine-readable export of a subject's log rows (subject-access / portability). Returns the rows'
     * facts as plain arrays; skips rows already pseudonymised.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(string $email, ?Member $member = null): array
    {
        $out = [];

        /** @var SentNotification $row */
        foreach ($this->rowsFor($email, $member) as $row) {
            if ($row->PseudonymisedAt) {
                continue;
            }
            $out[] = [
                'SentAt' => $row->SentAt,
                'NotificationKey' => $row->NotificationKey,
                'NotificationLabel' => $row->NotificationLabel,
                'Category' => $row->Category,
                'Subject' => $row->Subject,
                'Recipient' => $row->Recipient,
                'Success' => (bool) $row->Success,
                'OrderID' => (int) $row->OrderID,
            ];
        }

        return $out;
    }

    /**
     * Rows for a subject: linked by Member, or whose raw recipient matches the address (case-insensitive).
     */
    public function rowsFor(string $email, ?Member $member = null): DataList
    {
        $filter = ['Recipient:nocase' => $email];
        if ($member && $member->ID) {
            // Match either the Member link or the raw recipient address.
            return SentNotification::get()->filterAny([
                'MemberID' => $member->ID,
                'Recipient:nocase' => $email,
            ]);
        }

        return SentNotification::get()->filter($filter);
    }

    protected function hash(string $value): string
    {
        $salt = (string) static::config()->get('pseudonymise_salt');

        return 'sha256:' . hash('sha256', $salt . strtolower(trim($value)));
    }
}
