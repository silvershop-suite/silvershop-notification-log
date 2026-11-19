<?php

namespace SilverShop\NotificationLog\Extension;

use Psr\Log\LoggerInterface;
use SilverShop\NotificationLog\Service\NotificationCapture;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Mailer\Event\MessageEvent;
use Throwable;

/**
 * The capture chokepoint. Applied (via one YAML line) to the framework's
 * {@link \SilverStripe\Control\Email\MailerSubscriber}, whose onMessage() ends with
 * `$this->extend('updateOnMessage', $email, $event)` — so every Email::send() in the whole app funnels
 * through this single, built-in extension point. No Injector override, no Mailer decorator, and no change
 * to any sending module.
 *
 * At this point $email is the fully-rendered message with final To/From/Subject/body and absolute URLs
 * (the subscriber has already applied send_all_emails_* and URL rewriting), which is exactly what we log.
 */
class NotificationLogMailerExtension extends Extension
{
    public function updateOnMessage(Email $email, MessageEvent $event): void
    {
        try {
            NotificationCapture::singleton()->record($email, $event);
        } catch (Throwable $e) {
            // Capturing the log must never block or break a send. Swallow and record for ops.
            Injector::inst()->get(LoggerInterface::class)->warning(
                'silvershop-suite/notification-log: failed to capture a sent email: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
