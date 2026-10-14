<?php

namespace SilverShop\NotificationLog\Tests\Service;

use SilverShop\Model\Order;
use SilverShop\NotificationLog\Model\SentNotification;
use SilverShop\NotificationLog\Service\NotificationCapture;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\MailerSubscriber;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * Drives the real capture chokepoint — MailerSubscriber::onMessage() → the extension → the capture service
 * — with a synthesised MessageEvent, and asserts the row, its attribution, and the guards.
 */
class NotificationCaptureTest extends SapphireTest
{
    protected static $fixture_file = 'NotificationCaptureTest.yml';

    protected static bool $use_draft_site = true;

    private function makeEvent(Email $email, bool $queued = false): MessageEvent
    {
        $recipients = [];
        foreach ($email->getTo() as $address) {
            $recipients[] = $address;
        }
        if ($recipients === []) {
            $recipients = [new Address('nobody@example.com')];
        }
        $envelope = new Envelope(new Address('shop@example.com'), $recipients);

        return new MessageEvent($email, $envelope, 'test-transport', $queued);
    }

    private function fire(Email $email, bool $queued = false): MessageEvent
    {
        $event = $this->makeEvent($email, $queued);
        MailerSubscriber::create()->onMessage($event);

        return $event;
    }

    public function testCapturesRowWithMemberAndOrderAttribution(): void
    {
        $order = $this->objFromFixture(Order::class, 'order1');
        $member = $this->objFromFixture(Member::class, 'customer');

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('customer@example.com')
            ->setSubject('Your receipt')
            ->setHTMLTemplate('SilverShop/Model/Order_ReceiptEmail');
        // Order attribution via the zero-touch getData() sniff (core passes 'Order' into setData()).
        $email->setData(['Order' => $order]);

        $this->fire($email);

        $row = SentNotification::get()->filter('Recipient', 'customer@example.com')->first();
        $this->assertNotNull($row, 'a row is written on send');
        $this->assertSame('Your receipt', $row->Subject);
        $this->assertSame('order.receipt', $row->NotificationKey, 'key guessed from the template basename');
        $this->assertSame('Transactional', $row->Category);
        $this->assertSame((int) $member->ID, (int) $row->MemberID, 'attributed to the Member by recipient match');
        $this->assertSame((int) $order->ID, (int) $row->OrderID, 'attributed to the Order via getData() sniff');
        $this->assertTrue((bool) $row->Success);
        $this->assertNull($row->Body, 'body not stored by default');
    }

    public function testOrderAttributionViaHeaderTag(): void
    {
        $order = $this->objFromFixture(Order::class, 'order1');

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('someoneelse@example.com')
            ->setSubject('Tagged');
        $email->getHeaders()->addTextHeader(NotificationCapture::HEADER_ORDER_ID, (string) $order->ID);
        $email->getHeaders()->addTextHeader(NotificationCapture::HEADER_KEY, 'order.confirmation');

        $this->fire($email);

        $row = SentNotification::get()->filter('Recipient', 'someoneelse@example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $order->ID, (int) $row->OrderID, 'order read from the X-SilverShop-Order-ID header');
        $this->assertSame('order.confirmation', $row->NotificationKey, 'key read from the header tag');
    }

    public function testAdminRecipientIsCategorisedAdmin(): void
    {
        Email::config()->set('admin_email', 'admin@example.com');

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('admin@example.com')
            ->setSubject('New order')
            ->setHTMLTemplate('SilverShop/Model/Order_AdminNotificationEmail');

        $this->fire($email);

        $row = SentNotification::get()->filter('Recipient', 'admin@example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame('Admin', $row->Category);
        $this->assertSame(0, (int) $row->MemberID, 'admin copy resolves to no member');
    }

    public function testQueuedEventIsNotLogged(): void
    {
        $before = SentNotification::get()->count();

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('customer@example.com')
            ->setSubject('Queued');
        $this->fire($email, true);

        $this->assertSame($before, SentNotification::get()->count(), 'queued events are skipped (no double-log)');
    }

    public function testRejectedEventIsNotLogged(): void
    {
        $before = SentNotification::get()->count();

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('customer@example.com')
            ->setSubject('Rejected');
        $event = $this->makeEvent($email);
        $event->reject();
        MailerSubscriber::create()->onMessage($event);

        $this->assertSame($before, SentNotification::get()->count(), 'rejected sends are skipped');
    }

    public function testStoreBodyOptIn(): void
    {
        NotificationCapture::config()->set('store_body', true);

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('customer@example.com')
            ->setSubject('With body');
        $email->html('<p>Hello Casey</p>');

        $this->fire($email);

        $row = SentNotification::get()->filter('Recipient', 'customer@example.com')->sort('ID', 'DESC')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('Hello Casey', (string) $row->Body, 'body stored when opted in');
    }

    public function testScopeSkipsUnattributedMailWhenLogAllDisabled(): void
    {
        NotificationCapture::config()->set('log_all_emails', false);
        $before = SentNotification::get()->count();

        $email = Email::create()
            ->setFrom('shop@example.com')
            ->setTo('stranger@nowhere.test')
            ->setSubject('Unrelated system email');

        $this->fire($email);

        $this->assertSame(
            $before,
            SentNotification::get()->count(),
            'with log_all_emails off, mail with no key/order/member is not logged'
        );
    }
}
