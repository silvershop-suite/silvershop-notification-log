<?php

namespace SilverShop\NotificationLog\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;

/**
 * Applied to {@link \SilverStripe\SiteConfig\SiteConfig}. Lets a shop set its own retention windows in the
 * CMS (Settings → Notifications) without a deploy — the concrete expression of GDPR storage-limitation
 * (Art. 5(1)(e)). Any value > 0 overrides the YAML config; 0 falls back to the YAML config value.
 */
class SiteConfigNotificationLogExtension extends Extension
{
    private static array $db = [
        'NotificationLogKeepDays' => 'Int',
        'NotificationLogKeepDaysTransactional' => 'Int',
        'NotificationLogKeepDaysMarketing' => 'Int',
        'NotificationLogKeepBodyDays' => 'Int',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldsToTab('Root.Notifications', [
            NumericField::create(
                'NotificationLogKeepDays',
                _t(self::class . '.KeepDays', 'Retention — all notifications (days)')
            )->setDescription(_t(
                self::class . '.KeepDaysDesc',
                'How long sent-notification records are kept before pruning. 0 = use the site default.'
            )),
            NumericField::create(
                'NotificationLogKeepDaysTransactional',
                _t(self::class . '.KeepDaysTransactional', 'Retention — transactional (days)')
            )->setDescription(_t(
                self::class . '.KeepDaysTransactionalDesc',
                'Order confirmations, receipts, status updates. Usually longer (contract / legitimate '
                . 'interest). 0 = use the all-notifications window.'
            )),
            NumericField::create(
                'NotificationLogKeepDaysMarketing',
                _t(self::class . '.KeepDaysMarketing', 'Retention — marketing (days)')
            )->setDescription(_t(
                self::class . '.KeepDaysMarketingDesc',
                'Newsletter / review / stock-alert emails. Usually shorter (consent). 0 = use the '
                . 'all-notifications window.'
            )),
            NumericField::create(
                'NotificationLogKeepBodyDays',
                _t(self::class . '.KeepBodyDays', 'Retention — stored message bodies (days)')
            )->setDescription(_t(
                self::class . '.KeepBodyDaysDesc',
                'When bodies are stored, drop them this many days after sending while keeping the '
                . 'metadata row. 0 = keep bodies as long as the row.'
            )),
        ]);
        $fields->findOrMakeTab('Root.Notifications')
            ->setTitle(_t(self::class . '.TAB', 'Notifications'));
    }
}
