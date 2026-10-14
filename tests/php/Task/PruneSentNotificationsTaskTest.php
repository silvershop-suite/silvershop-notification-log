<?php

namespace SilverShop\NotificationLog\Tests\Task;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverShop\NotificationLog\Task\PruneSentNotificationsTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Covers retention: per-category deletion windows (with the transactional/marketing split), the legal-hold
 * guard, and body pruning that keeps the metadata row.
 */
class PruneSentNotificationsTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-06-01 12:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function makeRow(string $category, string $sentAt, array $props = []): SentNotification
    {
        $row = SentNotification::create();
        $row->Recipient = $props['Recipient'] ?? 'customer@example.com';
        $row->Subject = 'Hello';
        $row->NotificationKey = $props['NotificationKey'] ?? 'test';
        $row->Category = $category;
        $row->SentAt = $sentAt;
        $row->Success = true;
        if (array_key_exists('Body', $props)) {
            $row->Body = $props['Body'];
        }
        if (array_key_exists('LegalHold', $props)) {
            $row->LegalHold = $props['LegalHold'];
        }
        $row->write();

        return $row;
    }

    private function runTask(): void
    {
        $task = PruneSentNotificationsTask::create();
        $task->run(new ArrayInput([]), PolyOutput::create(PolyOutput::FORMAT_ANSI));
    }

    public function testPerCategoryRetentionWithSplit(): void
    {
        SentNotification::config()->set('keep_days', 365);
        SentNotification::config()->set('keep_days_transactional', 0); // falls back to 365
        SentNotification::config()->set('keep_days_marketing', 30);    // shorter

        // now = 2026-06-01. Transactional cutoff = 2025-06-01; Marketing cutoff = 2026-05-02.
        $txOld = $this->makeRow('Transactional', '2025-01-01 00:00:00');   // > 365d -> deleted
        $txRecent = $this->makeRow('Transactional', '2026-05-01 00:00:00'); // < 365d -> kept
        $mkOld = $this->makeRow('Marketing', '2026-03-01 00:00:00');       // > 30d -> deleted
        $mkRecent = $this->makeRow('Marketing', '2026-05-25 00:00:00');    // < 30d -> kept

        $this->runTask();

        $this->assertNull(SentNotification::get()->byID($txOld->ID), 'old transactional pruned');
        $this->assertNotNull(SentNotification::get()->byID($txRecent->ID), 'recent transactional kept');
        $this->assertNull(SentNotification::get()->byID($mkOld->ID), 'old marketing pruned (shorter window)');
        $this->assertNotNull(SentNotification::get()->byID($mkRecent->ID), 'recent marketing kept');
    }

    public function testLegalHoldRowNotPruned(): void
    {
        SentNotification::config()->set('keep_days', 365);
        $held = $this->makeRow('Transactional', '2024-01-01 00:00:00', ['LegalHold' => true]);

        $this->runTask();

        $this->assertNotNull(SentNotification::get()->byID($held->ID), 'legal-hold row survives pruning');
    }

    public function testUnboundedWindowDeletesNothing(): void
    {
        SentNotification::config()->set('keep_days', 0); // 0 = unbounded
        SentNotification::config()->set('keep_days_transactional', 0);
        SentNotification::config()->set('keep_days_marketing', 0);
        $old = $this->makeRow('Transactional', '2000-01-01 00:00:00');

        $this->runTask();

        $this->assertNotNull(SentNotification::get()->byID($old->ID), 'nothing pruned when window is 0');
    }

    public function testBodyPruneKeepsMetadataRow(): void
    {
        SentNotification::config()->set('keep_days', 3650);     // keep metadata long
        SentNotification::config()->set('keep_body_days', 7);   // but drop old bodies

        // now = 2026-06-01. Body cutoff = 2026-05-25.
        $oldBody = $this->makeRow('Transactional', '2026-01-01 00:00:00', ['Body' => '<p>old</p>']);
        $freshBody = $this->makeRow('Transactional', '2026-05-30 00:00:00', ['Body' => '<p>fresh</p>']);

        $this->runTask();

        $oldFresh = SentNotification::get()->byID($oldBody->ID);
        $this->assertNotNull($oldFresh, 'metadata row kept');
        $this->assertEmpty($oldFresh->Body, 'old body dropped');

        $this->assertStringContainsString(
            'fresh',
            (string) SentNotification::get()->byID($freshBody->ID)->Body,
            'recent body kept'
        );
    }
}
