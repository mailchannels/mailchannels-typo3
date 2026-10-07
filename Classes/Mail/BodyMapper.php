<?php

declare(strict_types=1);
namespace MailChannels\Typo3\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\{AbstractPart,DataPart,TextPart};
use Symfony\Component\Mime\Part\Multipart\{AlternativePart,MixedPart,RelatedPart};

/** Converts supported native MIME trees; not a raw RFC-message parser. */
final class BodyMapper
{
    private array $content = [];
    private array $attachments = [];
    private array $cids = [];
    private int $bytes = 0;
    private int $parts = 0;
    private function __construct(private int $maxBytes) {}

    public static function map(Email $message, int $maxBytes = 20971520): array
    {
        if ($maxBytes < 1 || $maxBytes > 104857600) throw new \InvalidArgumentException('Invalid local content limit.');
        $mapper = new self($maxBytes);
        // Symfony resolves named cid references while generating its MIME tree.
        $mapper->walk($message->getBody(), 0);
        if (!$mapper->content) throw new \InvalidArgumentException('Message content is required.');
        $content = [];
        foreach (['text/plain','text/html'] as $type) {
            if (array_key_exists($type, $mapper->content)) $content[] = ['type' => $type, 'value' => $mapper->content[$type]];
        }
        $result = ['content' => $content];
        if ($mapper->attachments) $result['attachments'] = $mapper->attachments;
        return $result;
    }

    private function walk(AbstractPart $part, int $depth): void
    {
        if ($depth > 10 || ++$this->parts > 1000) throw new \InvalidArgumentException('MIME structure exceeds local limits.');
        if ($part instanceof AlternativePart || $part instanceof MixedPart || $part instanceof RelatedPart) {
            foreach ($part->getPreparedHeaders()->getNames() as $name) {
                if (strtolower($name) !== 'content-type') throw new \InvalidArgumentException('Unsupported multipart header.');
            }
            foreach ($part->getParts() as $index => $child) {
                if ($part instanceof AlternativePart && (!$child instanceof TextPart || $child instanceof DataPart)) {
                    throw new \InvalidArgumentException('Unsupported alternative MIME structure.');
                }
                if (!$part instanceof AlternativePart && $index > 0 && !$child instanceof DataPart) {
                    throw new \InvalidArgumentException('Unsupported mixed or related MIME structure.');
                }
                if ($part instanceof RelatedPart && $index > 0 && $child->getDisposition() !== 'inline') {
                    throw new \InvalidArgumentException('Related MIME attachment must be inline.');
                }
                $this->walk($child, $depth + 1);
            }
            return;
        }
        if (!$part instanceof TextPart) throw new \InvalidArgumentException('Unsupported MIME part.');
        $headers = $part->getPreparedHeaders();
        foreach ($headers->getNames() as $name) {
            if (!in_array(strtolower($name), ['content-type','content-transfer-encoding','content-disposition','content-id'], true)) {
                throw new \InvalidArgumentException('Unsupported MIME part header.');
            }
        }
        $encoding = strtolower($headers->get('Content-Transfer-Encoding')->getBodyAsString());
        $encoded = '';
        try {
            foreach ($part->bodyToIterable() as $chunk) {
                if (strlen($encoded) + strlen($chunk) > $this->maxBytes * 4 + 1024) throw new \InvalidArgumentException('Encoded part exceeds local limit.');
                $encoded .= $chunk;
            }
        } catch (\Throwable) { throw new \InvalidArgumentException('MIME content could not be read within local limits.'); }
        $raw = match ($encoding) {
            'base64' => base64_decode($encoded, true),
            'quoted-printable' => quoted_printable_decode($encoded),
            '8bit' => $encoded,
            default => throw new \InvalidArgumentException('Unsupported MIME transfer encoding.'),
        };
        if ($raw === false) throw new \InvalidArgumentException('Invalid encoded MIME content.');
        $this->bytes += strlen($raw);
        if ($this->bytes > $this->maxBytes) throw new \InvalidArgumentException('Message exceeds local content limit.');
        $type = $part->getMediaType().'/'.$part->getMediaSubtype();
        if (!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $type)) throw new \InvalidArgumentException('Invalid MIME type.');
        if ($part instanceof DataPart) {
            $filename = $part->getFilename() ?? 'attachment.dat';
            if ($filename === '' || !mb_check_encoding($filename, 'UTF-8') || preg_match('~[\x00-\x1f\x7f/\\\\]~', $filename)) throw new \InvalidArgumentException('Invalid attachment filename.');
            if ($headers->has('Content-ID') && !$part->hasContentId()) throw new \InvalidArgumentException('Content ID must use native attachment metadata.');
            $disposition = $part->getDisposition();
            if (!in_array($disposition, ['attachment','inline'], true)) throw new \InvalidArgumentException('Unsupported attachment disposition.');
            $attachment = ['content' => base64_encode($raw), 'type' => $type, 'filename' => $filename, 'disposition' => $disposition];
            if ($disposition === 'inline') {
                $cid = $part->getContentId();
                if (!preg_match('~^[^\s<>\x00-\x1f\x7f]+$~D', $cid) || isset($this->cids[$cid])) throw new \InvalidArgumentException('Invalid or duplicate inline ID.');
                $this->cids[$cid] = true;
                $attachment['content_id'] = $cid;
            } elseif ($part->hasContentId()) {
                throw new \InvalidArgumentException('Content ID requires inline disposition.');
            }
            $this->attachments[] = $attachment;
            return;
        }
        if (!in_array($type, ['text/plain','text/html'], true) || $part->getDisposition() !== null || $headers->has('Content-ID') || array_key_exists($type, $this->content)) {
            throw new \InvalidArgumentException('Unsupported or ambiguous text content.');
        }
        $charset = $headers->get('Content-Type')->getParameter('charset') ?? 'utf-8';
        try {
            if (!mb_check_encoding($raw, $charset)) throw new \InvalidArgumentException();
            $text = mb_convert_encoding($raw, 'UTF-8', $charset);
        } catch (\Throwable) { throw new \InvalidArgumentException('Invalid text charset or bytes.'); }
        $this->bytes += strlen($text) - strlen($raw);
        if ($this->bytes > $this->maxBytes) throw new \InvalidArgumentException('Converted text exceeds local limit.');
        $this->content[$type] = $text;
    }
}
