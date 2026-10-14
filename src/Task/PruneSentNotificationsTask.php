<?php

namespace SilverShop\NotificationLog\Task;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Enforces the configured retention policy (GDPR Art. 5(1)(e) storage limitation). Cron-scheduled:
 *
 *   sake tasks:SilverShop-NotificationLog-Prune
 *
 * Two passes, both skipping rows under a legal hold:
 *  1. Metadata prune — delete rows older than the per-category window (keep_days, split into
 *     keep_days_transactional / keep_days_marketing), each resolved from SiteConfig then YAML config.
 *  2. Body prune — drop stored message bodies older than keep_body_days while keeping the metadata row.
 *
 * A window of 0 means "unbounded" (that pass is skipped), so an unconfigured install never deletes until
 * a policy is set. The shipped default keep_days is 365.
 */
class PruneSentNotificationsTask extends BuildTask
{
    protected string $title = 'Notification log: prune records past their retention window';

    protected static string $description =
        'Deletes sent-notification records older than the configured retention window (per category) and '
        . 'drops stored bodies older than the body-retention window. Skips rows under a legal hold.';

    protected static string $commandName = 'SilverShop-NotificationLog-Prune';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $nowTs = strtotime((string) DBDatetime::now()->getValue());
        $deleted = 0;

        foreach (['Transactional', 'Marketing', 'Admin'] as $category) {
            $keepDays = SentNotification::keepDaysForCategory($category);
            if ($keepDays <= 0) {
                continue;
            }

            $cutoff = date('Y-m-d H:i:s', $nowTs - $keepDays * 86400);
            $ids = SentNotification::get()->filter([
                'Category' => $category,
                'SentAt:LessThan' => $cutoff,
                'LegalHold' => false,
            ])->column('ID');

            foreach ($ids as $id) {
                $row = SentNotification::get()->byID($id);
                if ($row) {
                    $row->delete();
                    $deleted++;
                }
            }

            $output->writeln(sprintf(
                'Category %s: keep %d days (cutoff %s) — removed %d record(s).',
                $category,
                $keepDays,
                $cutoff,
                count($ids)
            ));
        }

        $bodiesPruned = 0;
        $bodyDays = SentNotification::keepBodyDays();
        if ($bodyDays > 0) {
            $cutoff = date('Y-m-d H:i:s', $nowTs - $bodyDays * 86400);
            $ids = SentNotification::get()->filter([
                'SentAt:LessThan' => $cutoff,
                'LegalHold' => false,
            ])->column('ID');

            foreach ($ids as $id) {
                $row = SentNotification::get()->byID($id);
                if ($row && $row->Body) {
                    $row->Body = null;
                    $row->write();
                    $bodiesPruned++;
                }
            }

            $output->writeln(sprintf(
                'Body prune: keep %d days — dropped %d stored body/bodies.',
                $bodyDays,
                $bodiesPruned
            ));
        }

        $output->writeln(sprintf('Done. %d record(s) deleted, %d body/bodies dropped.', $deleted, $bodiesPruned));
        $output->writeln('Records remaining: ' . SentNotification::get()->count());

        return Command::SUCCESS;
    }
}
