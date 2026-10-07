<?php

declare(strict_types=1);
namespace MailChannels\Typo3\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

final class TransportFailure extends TransportException
{
    public function __construct(public readonly string $outcome)
    {
        parent::__construct('MailChannels outcome: '.$outcome.'. Do not automatically retry unconfirmed acceptance.');
    }
}
