<?php

namespace SilverShop\NotificationLog\Admin;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldExportButton;

/**
 * A global, read-only view of every sent-notification record across all orders and members — searchable
 * by notification key, category, recipient and order reference, with CSV export.
 *
 * Records are immutable and code-generated, so the grid uses a RecordViewer config: each row has a view
 * (not edit) action and there is no add control; import is disabled.
 */
class NotificationLogAdmin extends ModelAdmin
{
    private static string $url_segment = 'notification-log';

    private static string $menu_title = 'Notifications';

    private static string $menu_icon_class = 'font-icon-p-mail';

    private static array $managed_models = [
        SentNotification::class,
    ];

    /**
     * The log is immutable and code-generated — never import rows into it (export stays available).
     */
    public $showImportForm = false;

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
        if ($grid instanceof GridField) {
            // Immutable records: view-only rows (no edit pencil), but keep the CSV export.
            $config = GridFieldConfig_RecordViewer::create();
            $config->addComponent(GridFieldExportButton::create('buttons-before-left'));
            $grid->setConfig($config);
        }

        return $form;
    }
}
