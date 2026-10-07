<?php

declare(strict_types=1);

namespace MailChannels\Typo3\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Routing fragment only; never a complete send payload or transport. */
final class EnvelopeMapper
{
    public static function map(Email $message, ?Envelope $envelope, array $allowedSenders): array
    {
        if (count($message->getFrom()) !== 1 || !$message->getTo()) {
            throw new \InvalidArgumentException('Exactly one From and nonempty To are required.');
        }
        if (count($message->getReplyTo()) > 1) {
            throw new \InvalidArgumentException('Multiple Reply-To addresses are unsupported.');
        }
        $envelope ??= Envelope::create($message);
        $visible = array_merge($message->getTo(), $message->getCc(), $message->getBcc());
        if (self::recipientSet($visible) !== self::recipientSet($envelope->getRecipients())) {
            throw new \InvalidArgumentException('Distinct visible and envelope recipients are unsupported.');
        }
        $from = self::address($message->getFrom()[0]);
        $bounce = self::address($envelope->getSender());
        $sender = $message->getSender();
        $allowed = [];
        foreach ($allowedSenders as $value) {
            if (!is_string($value)) throw new \InvalidArgumentException('Invalid sender policy.');
            try { $allowed[] = self::address(new Address($value))['email']; }
            catch (\Throwable) { throw new \InvalidArgumentException('Invalid sender policy.'); }
        }
        foreach (array_filter([$message->getFrom()[0], $envelope->getSender(), $sender]) as $identity) {
            if (!in_array(self::address($identity)['email'], $allowed, true)) {
                throw new \InvalidArgumentException('Sender identity is not allowed.');
            }
        }
        $personalization = [];
        foreach (['to' => $message->getTo(), 'cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $role => $addresses) {
            if ($addresses) $personalization[$role] = array_map(self::address(...), $addresses);
        }
        $result = ['from' => $from, 'personalizations' => [$personalization], 'envelope_from' => ['email' => $bounce['email']]];
        if ($message->getReplyTo()) $result['reply_to'] = self::address($message->getReplyTo()[0]);
        if ($sender !== null) $result['headers']['Sender'] = $sender->toString();
        return $result;
    }

    private static function recipientSet(array $addresses): array
    {
        $emails = array_unique(array_map(static fn(Address $address): string => self::address($address)['email'], $addresses));
        sort($emails, SORT_STRING);
        return $emails;
    }

    private static function address(Address $address): array
    {
        try { $email = $address->getEncodedAddress(); }
        catch (\Throwable) { throw new \InvalidArgumentException('Unsupported address representation.'); }
        $name = $address->getName();
        // Require ASCII addr-spec; display names may be UTF-8. No SMTPUTF8 claim.
        if (preg_match('/[^\x21-\x7e]/', $email) || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \InvalidArgumentException('Unsupported address representation.');
        }
        $result = ['email' => $email];
        if ($name !== '') $result['name'] = $name;
        return $result;
    }
}
