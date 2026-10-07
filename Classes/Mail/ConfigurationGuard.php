<?php

declare(strict_types=1);
namespace MailChannels\Typo3\Mail;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;

/** Checks effective routing before the core Mailer calls send or enqueues mail. */
#[AsEventListener(identifier: 'mailchannels/direct-transport-configuration')]
final class ConfigurationGuard
{
    public function __invoke(BeforeMailerSentMessageEvent $event): void
    {
        $settings = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        $selected = ($settings['transport'] ?? null) === ApiTransport::class;
        $mailer = $event->getMailer();
        if (!$mailer instanceof MailerInterface) {
            if ($selected) throw new TransportFailure('configuration_invalid');
            return;
        }
        $actual = $mailer->getTransport();
        if (!$selected && !$actual instanceof ApiTransport) return;
        if (!$selected || !$actual instanceof ApiTransport || !empty($settings['dsn'])
            || !empty($settings['transport_spool_type']) || !$actual->matchesConfiguration($settings)) {
            throw new TransportFailure('configuration_invalid');
        }
    }
}
