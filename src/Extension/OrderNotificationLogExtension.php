<?php

namespace SilverShop\NotificationLog\Extension;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;

/**
 * Applied to {@link \SilverShop\Model\Order}. Adds a read-only "Notifications" tab listing every email
 * logged against the order (newest first). View-only — records are immutable and code-generated.
 *
 * With silvershop-suite/order-admin installed the tab sits among its regrouped tabs; standalone it is a plain
 * top-level tab. Either way it mirrors silvershop-suite/ledger's Root.Ledger grid.
 */
class OrderNotificationLogExtension extends Extension
{
    public function updateCMSFields(FieldList $fields): void
    {
        $order = $this->getOwner();
        if (!$order->isInDB()) {
            return;
        }

        $fields->addFieldToTab('Root.Notifications', GridField::create(
            'SentNotifications',
            _t(self::class . '.NOTIFICATIONS', 'Notifications'),
            SentNotification::get()->filter('OrderID', $order->ID),
            GridFieldConfig_RecordViewer::create()
        ));
        $fields->findOrMakeTab('Root.Notifications')
            ->setTitle(_t(self::class . '.TAB', 'Notifications'));
    }
}
