<?php

namespace SilverShop\NotificationLog\Extension;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverShop\NotificationLog\Service\NotificationErasureService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;

/**
 * Applied to {@link \SilverStripe\Security\Member}. Adds the read-only "Notifications" grid to the member
 * in SecurityAdmin (the per-subject disclosure surface for Art. 15 access requests), and exposes the
 * erase()/export() hooks a central "forget / export this customer" flow can call.
 *
 * On a Member delete the member's log rows are erased too (pseudonymised by default), so deleting a member
 * does not leave identifying notification rows behind. Disable with erase_notifications_on_delete = false.
 */
class MemberNotificationLogExtension extends Extension
{
    use Configurable;

    private static bool $erase_notifications_on_delete = true;

    public function updateCMSFields(FieldList $fields): void
    {
        $member = $this->getOwner();
        if (!$member->isInDB()) {
            return;
        }

        $fields->addFieldToTab('Root.Notifications', GridField::create(
            'SentNotifications',
            _t(self::class . '.NOTIFICATIONS', 'Notifications'),
            SentNotification::get()->filter('MemberID', $member->ID),
            GridFieldConfig_RecordViewer::create()
        ));
        $fields->findOrMakeTab('Root.Notifications')
            ->setTitle(_t(self::class . '.TAB', 'Notifications'));
    }

    /**
     * Erase (pseudonymise or delete, per config) this member's notification-log rows. Callable from a
     * central erasure flow: `$member->eraseNotifications()`.
     */
    public function eraseNotifications(): int
    {
        $member = $this->getOwner();

        return NotificationErasureService::singleton()->erase((string) $member->Email, $member);
    }

    /**
     * Export this member's notification-log rows (subject access / portability).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportNotifications(): array
    {
        $member = $this->getOwner();

        return NotificationErasureService::singleton()->export((string) $member->Email, $member);
    }

    public function onBeforeDelete(): void
    {
        if (static::config()->get('erase_notifications_on_delete')) {
            $this->eraseNotifications();
        }
    }
}
