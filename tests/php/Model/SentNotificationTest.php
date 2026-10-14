<?php

namespace SilverShop\NotificationLog\Tests\Model;

use SilverShop\NotificationLog\Model\SentNotification;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Covers immutability: the factual record cannot be edited/created/deleted via the CMS, a change to a
 * factual field throws, and only the administrative (erasure/pseudonymise) fields may be rewritten.
 */
class SentNotificationTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function makeRow(array $props = []): SentNotification
    {
        $row = SentNotification::create();
        $row->Recipient = $props['Recipient'] ?? 'customer@example.com';
        $row->RecipientName = $props['RecipientName'] ?? 'Casey Customer';
        $row->Subject = $props['Subject'] ?? 'Hello';
        $row->NotificationKey = $props['NotificationKey'] ?? 'order.receipt';
        $row->Category = $props['Category'] ?? 'Transactional';
        $row->SentAt = $props['SentAt'] ?? DBDatetime::now()->getValue();
        $row->Success = $props['Success'] ?? true;
        $row->write();

        return $row;
    }

    public function testPermissionsAreLockedDown(): void
    {
        $row = $this->makeRow();
        $this->assertFalse($row->canEdit(), 'records cannot be edited in the CMS');
        $this->assertFalse($row->canDelete(), 'records cannot be deleted in the CMS');
        $this->assertFalse($row->canCreate(), 'records are only ever created in code');
    }

    public function testCodeCanStillWriteDespiteCanCreateFalse(): void
    {
        $row = $this->makeRow();
        $this->assertTrue($row->isInDB(), 'the capture hook can still write rows even though canCreate is false');
    }

    public function testChangingAFactualFieldThrows(): void
    {
        $row = $this->makeRow();
        $row->NotificationKey = 'order.changed';

        $this->expectException(ValidationException::class);
        $row->write();
    }

    public function testAdministrativeWriteIsAllowed(): void
    {
        $row = $this->makeRow();

        // The erasure/pseudonymise fields may be rewritten on an existing row.
        $row->Subject = '[redacted]';
        $row->RecipientName = '';
        $row->MemberID = 0;
        $row->PseudonymisedAt = DBDatetime::now()->getValue();
        $row->write();

        $fresh = SentNotification::get()->byID($row->ID);
        $this->assertSame('[redacted]', $fresh->Subject);
        $this->assertNotEmpty($fresh->PseudonymisedAt);
    }
}
