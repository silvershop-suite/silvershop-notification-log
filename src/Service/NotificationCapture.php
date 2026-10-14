<?php

namespace SilverShop\NotificationLog\Service;

use SilverShop\Extension\MemberExtension;
use SilverShop\Model\Order;
use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * Turns a fully-rendered {@link Email} (as seen at the framework mailer chokepoint) into a persisted
 * {@link SentNotification} row: resolves the notification key + label, the GDPR category, and attributes
 * the row to a Member (recipient match) and an Order (header tag, else a getData() sniff).
 *
 * All logic lives here (not on the Extension) so it is unit-testable in isolation, and so the hot send
 * path can delegate to one injectable service.
 */
class NotificationCapture
{
    use Injectable;
    use Configurable;

    /**
     * Persist the email's body (exact bytes). Off by default — a metadata row is ~1 KB, a rendered HTML
     * body ~15-60 KB. This is the data-minimisation default (GDPR Art. 5(1)(c) / 25).
     */
    private static bool $store_body = false;

    /**
     * Log every email that passes the mailer, including non-shop ones (password resets, etc.). When
     * false, only rows that resolve to a shop context (a known NotificationKey, an Order, or a Member)
     * are kept — un-attributed mail is skipped.
     */
    private static bool $log_all_emails = true;

    /**
     * Map a notification-key prefix (the part before the first dot) to a GDPR category. Drives split
     * retention and records the lawful-basis family (contract vs consent).
     */
    private static array $category_map = [
        'order' => 'Transactional',
        'invoice' => 'Transactional',
        'receipt' => 'Transactional',
        'newsletter' => 'Marketing',
        'stockalert' => 'Marketing',
        'pricedrop' => 'Marketing',
        'review' => 'Marketing',
        'admin' => 'Admin',
    ];

    /**
     * Best-effort mapping from a core/module email template basename to a stable notification key, used
     * when no X-SilverShop-Notification-Key header is present. Senders that set the header get an exact
     * key; this keeps the log useful against an un-modified core.
     */
    private static array $template_key_map = [
        'Order_ConfirmationEmail' => 'order.confirmation',
        'Order_ReceiptEmail' => 'order.receipt',
        'Order_StatusEmail' => 'order.status',
        'Order_CancelEmail' => 'order.cancelled',
        'Order_AdminNotificationEmail' => 'admin.order',
    ];

    /**
     * Optional human label per notification key (English; translate via lang files). Falls back to a
     * humanised key when unmapped.
     */
    private static array $label_map = [
        'order.confirmation' => 'Order confirmation',
        'order.receipt' => 'Order receipt',
        'order.status' => 'Order status update',
        'order.cancelled' => 'Order cancelled',
        'admin.order' => 'Admin order notification',
    ];

    public const HEADER_ORDER_ID = 'X-SilverShop-Order-ID';
    public const HEADER_KEY = 'X-SilverShop-Notification-Key';
    public const HEADER_LABEL = 'X-SilverShop-Notification-Label';

    /**
     * Record one sent email. Returns the written row, or null when nothing was logged (queued/rejected
     * event, no recipient, or out of scope).
     */
    public function record(Email $email, MessageEvent $event): ?SentNotification
    {
        // Future-proof against a messenger bus (the queued event would otherwise double-log) and skip
        // sends another listener suppressed.
        if ($event->isQueued() || $event->isRejected()) {
            return null;
        }

        $recipients = $email->getTo();
        if ($recipients === []) {
            return null;
        }
        $to = $recipients[0];

        $key = $this->resolveKey($email);
        $order = $this->resolveOrder($email);
        $member = MemberExtension::get_by_identifier($to->getAddress());
        $category = $this->resolveCategory($key, $to->getAddress());

        // Scope: optionally skip un-attributed, non-shop mail.
        if (
            !static::config()->get('log_all_emails')
            && $key === '' && !$order && !$member
        ) {
            return null;
        }

        $row = SentNotification::create();
        $row->Recipient = $to->getAddress();
        $row->RecipientName = $to->getName();
        $row->Subject = (string) $email->getSubject();
        $row->NotificationKey = $key;
        $row->NotificationLabel = $this->resolveLabel($email, $key);
        $row->Category = $category;
        $row->SentAt = DBDatetime::now()->getValue();
        $row->Success = true; // optimistic in v1 (MessageEvent fires pre-send); authoritative status is v2
        $row->MessageID = $this->resolveMessageId($email);

        if ($order) {
            $row->OrderID = $order->ID;
        }
        if ($member) {
            $row->MemberID = $member->ID;
        }
        if (static::config()->get('store_body')) {
            $body = $email->getHtmlBody();
            $row->Body = is_string($body) ? $body : null;
        }

        $row->write();

        return $row;
    }

    /**
     * Notification key: the X-SilverShop-Notification-Key header if a sender set one, else a best-effort
     * guess from the HTML template basename, else empty.
     */
    protected function resolveKey(Email $email): string
    {
        $header = $this->headerValue($email, self::HEADER_KEY);
        if ($header !== '') {
            return $header;
        }

        $template = $email->getHTMLTemplate();
        $template = is_array($template) ? ($template[0] ?? '') : (string) $template;
        if ($template === '') {
            return '';
        }

        $basename = basename(str_replace('\\', '/', $template));
        $map = (array) static::config()->get('template_key_map');

        return (string) ($map[$basename] ?? '');
    }

    protected function resolveLabel(Email $email, string $key): string
    {
        $header = $this->headerValue($email, self::HEADER_LABEL);
        if ($header !== '') {
            return $header;
        }

        if ($key === '') {
            return '';
        }

        $map = (array) static::config()->get('label_map');
        if (isset($map[$key])) {
            return _t(self::class . '.LABEL_' . str_replace('.', '_', $key), (string) $map[$key]);
        }

        // Humanise: "order.confirmation" -> "Order confirmation".
        $human = ucfirst(trim(str_replace(['.', '_', '-'], ' ', $key)));

        return _t(self::class . '.LABEL_' . str_replace('.', '_', $key), $human);
    }

    protected function resolveCategory(string $key, string $recipient): string
    {
        // Admin copies always resolve to Admin regardless of key prefix.
        $adminEmail = (string) Email::config()->get('admin_email');
        if ($adminEmail !== '' && strcasecmp($recipient, $adminEmail) === 0) {
            return 'Admin';
        }

        if ($key !== '') {
            $prefix = strtolower(explode('.', $key)[0]);
            $map = (array) static::config()->get('category_map');
            if (isset($map[$prefix])) {
                return (string) $map[$prefix];
            }
        }

        return 'Transactional';
    }

    /**
     * Order attribution: the X-SilverShop-Order-ID header (preferred, deterministic), else a zero-touch
     * sniff of the email's template data (core order emails pass 'Order' into setData()).
     */
    protected function resolveOrder(Email $email): ?Order
    {
        $header = $this->headerValue($email, self::HEADER_ORDER_ID);
        if ($header !== '' && ctype_digit($header)) {
            $order = Order::get()->byID((int) $header);
            if ($order && $order->exists()) {
                return $order;
            }
        }

        // Fallback: sniff $email->getData()->Order (fragile; depends on the 'Order' data-key convention).
        $data = $email->getData();
        $candidate = $data->Order ?? null;
        if ($candidate instanceof Order && $candidate->exists()) {
            return $candidate;
        }

        return null;
    }

    protected function resolveMessageId(Email $email): ?string
    {
        $value = $this->headerValue($email, 'Message-ID');

        return $value !== '' ? $value : null;
    }

    protected function headerValue(Email $email, string $name): string
    {
        $headers = $email->getHeaders();
        if (!$headers->has($name)) {
            return '';
        }

        $header = $headers->get($name);
        if ($header === null) {
            return '';
        }

        return trim((string) $header->getBodyAsString());
    }
}
