<?php

declare(strict_types=1);
namespace MailChannels\Typo3\Mail;

use GuzzleHttp\{Client,ClientInterface,HandlerStack};
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\{FnStream,Utils};
use Symfony\Component\Mailer\{Envelope,SentMessage};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Direct synchronous transport. Native spools require separate integration. */
final class ApiTransport implements TransportInterface
{
    private ClientInterface $http;
    private string $key;
    private array $senders;
    private int $limit;

    public function __construct(#[\SensitiveParameter] array $settings, #[\SensitiveParameter] ?ClientInterface $http = null)
    {
        $key = $settings['mailchannels_api_key'] ?? null;
        $senders = $settings['mailchannels_allowed_senders'] ?? null;
        $limit = $settings['mailchannels_content_bytes'] ?? 20971520;
        if (!is_string($key) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key)
            || !is_array($senders) || !$senders || !is_int($limit) || $limit < 1 || $limit > 104857600
            || !empty($settings['dsn']) || !empty($settings['transport_spool_type'])) {
            throw new TransportFailure('configuration_invalid');
        }
        $this->key = $key;
        $this->senders = $senders;
        $this->limit = $limit;
        // Dedicated cURL stack: no application retry middleware or proxy routing.
        $this->http = $http ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    public function matchesConfiguration(#[\SensitiveParameter] array $settings): bool
    {
        return is_string($settings['mailchannels_api_key'] ?? null)
            && hash_equals($this->key, $settings['mailchannels_api_key'])
            && ($settings['mailchannels_allowed_senders'] ?? null) === $this->senders
            && ($settings['mailchannels_content_bytes'] ?? 20971520) === $this->limit;
    }

    public function __toString(): string { return 'mailchannels-email-api'; }
    public function __debugInfo(): array { return ['transport' => 'mailchannels-email-api', 'credentials' => '[redacted]']; }
    public function __serialize(): array { throw new \LogicException('Transport serialization is unsupported.'); }

    public function send(#[\SensitiveParameter] RawMessage $message, #[\SensitiveParameter] ?Envelope $envelope = null): ?SentMessage
    {
        try {
            $payload = PayloadBuilder::build($message, $envelope, $this->senders, $this->limit);
            $envelope ??= Envelope::create($message);
            $sent = new SentMessage($message, $envelope);
        } catch (\Throwable) { throw new TransportFailure('message_unsupported'); }
        $storage = Utils::streamFor('');
        $sink = FnStream::decorate($storage, ['write' => static function (string $chunk) use ($storage): int {
            if ($storage->getSize() + strlen($chunk) > 65536) throw new \RuntimeException('Response limit exceeded.');
            return $storage->write($chunk);
        }]);
        try {
            $response = $this->http->request('POST', 'https://api.mailchannels.net/tx/v1/send', [
                'headers' => ['X-Api-Key' => $this->key, 'Accept' => 'application/json'],
                'json' => $payload, 'verify' => true, 'allow_redirects' => false,
                'http_errors' => false, 'connect_timeout' => 5, 'timeout' => 15,
                'proxy' => '', 'sink' => $sink,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 202) {
                throw new TransportFailure($status >= 400 && $status < 500 && $status !== 408 ? 'rejected' : 'acceptance_unconfirmed');
            }
            // Also bound injected-client responses whose handler does not honor sink.
            $stream = $response->getBody();
            if ($stream->isSeekable()) $stream->rewind();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 65536) {
                $chunk = $stream->read(min(8192, 65537 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) throw new \RuntimeException('Incomplete response.');
                $raw .= $chunk;
            }
            if (strlen($raw) > 65536) throw new \RuntimeException('Response limit exceeded.');
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $results = $data['results'] ?? null;
            if (!is_array($results) || !array_is_list($results) || count($results) !== 1 || ($results[0]['index'] ?? null) !== 0) {
                throw new TransportFailure('acceptance_unconfirmed');
            }
            if (($results[0]['status'] ?? null) === 'failed') throw new TransportFailure('rejected');
            if (($results[0]['status'] ?? null) !== 'sent') throw new TransportFailure('acceptance_unconfirmed');
            return $sent; // API acceptance only; not proof of delivery.
        } catch (TransportFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new TransportFailure('acceptance_unconfirmed');
        } finally {
            $sink->close();
        }
    }
}
