<?php

namespace SilverShop\NotificationLog\Tests\Service;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverShop\NotificationLog\Service\NotificationErasureService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Covers erasure: pseudonymise (PII scrubbed, PseudonymisedAt set), legal-hold rows skipped, hard-delete
 * mode, and the export surface.
 */
class NotificationErasureServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function makeRow(array $props = []): SentNotification
    {
        $row = SentNotification::create();
        $row->Recipient = $props['Recipient'] ?? 'customer@example.com';
        $row->RecipientName = $props['RecipientName'] ?? 'Casey Customer';
        $row->Subject = $props['Subject'] ?? 'Your receipt';
        $row->NotificationKey = $props['NotificationKey'] ?? 'order.receipt';
        $row->Category = $props['Category'] ?? 'Transactional';
        $row->SentAt = $props['SentAt'] ?? DBDatetime::now()->getValue();
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

    public function testPseudonymiseScrubsPii(): void
    {
        $row = $this->makeRow(['Body' => '<p>secret</p>']);

        NotificationErasureService::singleton()->pseudonymise($row);

        $fresh = SentNotification::get()->byID($row->ID);
        $this->assertStringStartsWith('sha256:', (string) $fresh->Recipient, 'recipient is one-way hashed');
        $this->assertSame('', (string) $fresh->RecipientName);
        $this->assertSame('[redacted]', (string) $fresh->Subject);
        $this->assertEmpty($fresh->Body, 'stored body dropped');
        $this->assertSame(0, (int) $fresh->MemberID, 'member link nulled');
        $this->assertNotEmpty($fresh->PseudonymisedAt, 'PseudonymisedAt stamped');
        // The non-PII skeleton survives for stats.
        $this->assertSame('order.receipt', (string) $fresh->NotificationKey);
        $this->assertSame('Transactional', (string) $fresh->Category);
    }

    public function testEraseByEmailPseudonymisesMatchingRowsOnly(): void
    {
        $a = $this->makeRow(['Recipient' => 'customer@example.com']);
        $b = $this->makeRow(['Recipient' => 'customer@example.com', 'Subject' => 'Second']);
        $other = $this->makeRow(['Recipient' => 'someone.else@example.com']);

        $count = NotificationErasureService::singleton()->erase('customer@example.com');

        $this->assertSame(2, $count);
        $this->assertNotEmpty(SentNotification::get()->byID($a->ID)->PseudonymisedAt);
        $this->assertNotEmpty(SentNotification::get()->byID($b->ID)->PseudonymisedAt);
        $this->assertEmpty(
            SentNotification::get()->byID($other->ID)->PseudonymisedAt,
            'other subjects untouched'
        );
    }

    public function testLegalHoldRowIsSkipped(): void
    {
        $held = $this->makeRow(['Recipient' => 'held@example.com', 'LegalHold' => true]);

        $count = NotificationErasureService::singleton()->erase('held@example.com');

        $this->assertSame(0, $count, 'rows under legal hold are not erased');
        $fresh = SentNotification::get()->byID($held->ID);
        $this->assertSame('held@example.com', (string) $fresh->Recipient, 'PII preserved under legal hold');
        $this->assertEmpty($fresh->PseudonymisedAt);
    }

    public function testHardDeleteMode(): void
    {
        NotificationErasureService::config()->set('erasure_mode', 'delete');
        $row = $this->makeRow(['Recipient' => 'delete.me@example.com']);

        $count = NotificationErasureService::singleton()->erase('delete.me@example.com');

        $this->assertSame(1, $count);
        $this->assertNull(SentNotification::get()->byID($row->ID), 'row hard-deleted');
    }

    public function testExportReturnsFacts(): void
    {
        $this->makeRow(['Recipient' => 'export@example.com', 'Subject' => 'Receipt A']);
        $this->makeRow(['Recipient' => 'export@example.com', 'Subject' => 'Receipt B']);

        $export = NotificationErasureService::singleton()->export('export@example.com');

        $this->assertCount(2, $export);
        $this->assertArrayHasKey('NotificationKey', $export[0]);
        $this->assertArrayHasKey('Subject', $export[0]);
        $this->assertSame('export@example.com', $export[0]['Recipient']);
    }
}
