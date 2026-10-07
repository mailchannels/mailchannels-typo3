<?php

declare(strict_types=1);
namespace MailChannels\Typo3\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\{Email,RawMessage};

/** Complete JSON-ready payload for supported typed messages; performs no I/O transport. */
final class PayloadBuilder
{
    public static function build(RawMessage $message, ?Envelope $envelope, array $allowedSenders, int $maxBytes = 20971520): array
    {
        if (!$message instanceof Email) throw new \InvalidArgumentException('Typed Email conversion is required.');
        try {
            $message->ensureValidity();
            $subject = $message->getSubject() ?? '';
            self::text($subject);
            $custom = [];
            $seen = [];
            $mapped = ['from','to','cc','bcc','reply-to','sender','return-path','subject'];
            foreach ($message->getHeaders()->all() as $header) {
                $name = $header->getName();
                $lower = strtolower($name);
                if (!preg_match('/^[\x21-\x39\x3b-\x7e]+$/D', $name) || isset($seen[$lower])) throw new \InvalidArgumentException('Invalid or repeated header.');
                $seen[$lower] = true;
                if (in_array($lower, $mapped, true)) continue;
                if ($lower === 'mime-version') {
                    if (trim($header->getBodyAsString()) !== '1.0') throw new \InvalidArgumentException('Unsupported MIME version.');
                    continue;
                }
                // The provider generates these fields; resending signatures or MIME
                // serialization metadata would misrepresent the converted message.
                if (in_array($lower, ['authentication-results','dkim-signature','message-id','received','x-unsent'], true)
                    || str_starts_with($lower, 'content-') || str_starts_with($lower, 'resent-') || str_starts_with($lower, 'arc-')) {
                    throw new \InvalidArgumentException('Unsupported message header.');
                }
                $body = $header->getBody();
                if (is_string($body)) {
                    self::text($body);
                    $value = $body;
                } else {
                    // Unfold only whitespace introduced by structured header rendering.
                    $value = preg_replace('/\r\n[ \t]+/', ' ', $header->getBodyAsString());
                    self::text($value);
                }
                $custom[$name] = $value;
            }
            $payload = EnvelopeMapper::map($message, $envelope, $allowedSenders);
            $payload['subject'] = $subject;
            $payload = array_merge($payload, BodyMapper::map($message, $maxBytes));
            if ($custom) $payload['headers'] = array_merge($custom, $payload['headers'] ?? []);
            json_encode($payload, JSON_THROW_ON_ERROR);
            return $payload;
        } catch (\Throwable) {
            // Do not retain third-party exception messages/previous exceptions that
            // can contain recipients, message content or file paths.
            throw new \InvalidArgumentException('Message cannot be converted with the supported mail policy.');
        }
    }

    private static function text(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Invalid header text.');
        }
    }
}
