<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * Mail sent *to* a Resend receiving domain, read.
 *
 * Documentation: `resend.com/docs/dashboard/receiving/introduction`,
 * `/dashboard/receiving/custom-domains`, `/webhooks/emails/received`,
 * `/api-reference/emails/retrieve-received-email`,
 * `/api-reference/emails/list-received-email-attachments`, read 2026-09-23.
 *
 * This is the only class in the plugin that names the Email plugin's inbound
 * types, and nothing loads it unless that plugin has them: see
 * {@see ResendInboundProvider}.
 *
 * ## The webhook carries no message
 *
 * `email.received` is `{type, created_at, data}` like every other Resend event,
 * and `data` is the message's details only: `email_id`, `from` (the bare
 * address), `to`, `cc`, `bcc`, `received_for`, `message_id`, `subject` and the
 * attachments' names. Resend's own words: "Webhooks do not include the email
 * body, headers, or attachments, only their metadata." So {@see parse()}
 * answers an {@see InboundReference} carrying the `email_id`, and the consumer
 * calls {@see fetch()} later from its own job worker.
 *
 * ## Fetching it
 *
 * `GET /emails/receiving/{id}` with the plugin's API key answers the message as
 * JSON: `text`, `html`, a `headers` object, `authentication` and a `raw` block
 * whose `download_url` is a signed link to the original message, attachments
 * and all, valid for about an hour. The raw file is what is used, through
 * {@see InboundMessage::fromMime()}, because it is the message byte for byte and
 * the MIME parser already knows every corner of it. The link is fetched with
 * no API key on it: it carries its own signature and a CDN has no business
 * seeing the key.
 *
 * `raw` is documented as `object | null`. When it is null the message is built
 * from the JSON fields instead, and the attachments are downloaded one by one
 * from `GET /emails/receiving/{id}/attachments`, whose rows carry their own
 * signed `download_url`. The JSON is asked for with `html_format=cid`, so inline
 * images stay `cid:` references matching those attachments rather than being
 * inlined as `data:` URIs.
 *
 * `authentication` is the receiving server's own SPF, DKIM and DMARC results,
 * which the sender cannot forge, so they are laid over whatever the message's
 * headers claimed. Resend answers `pass`, `fail`, `gray` (none, softfail or
 * neutral: nothing either way), `processing_failed` and `unknown`; see
 * {@see AUTH}.
 *
 * ## Verification
 *
 * Svix, exactly as for delivery reports, so it goes through
 * {@see ResendReports::verify()}. The secret is a different one, though: Resend
 * mints a signing secret per webhook, and the webhook for `email.received`
 * points at a different address from the delivery-report webhook, so it is
 * kept under its own key, {@see SECRET_KEY}.
 */
final class ResendInbound implements InboundReceiver
{
    public const KEY = 'resend';

    /** The config key the `email.received` webhook's signing secret is kept under. */
    public const SECRET_KEY = 'inbound_signing_secret';

    /** The one event this receiver reads. */
    public const EVENT = 'email.received';

    /**
     * The largest webhook body accepted. The webhook is the message's details
     * only, a few kilobytes even with a long recipient list, so a megabyte is
     * generous.
     */
    public const MAX_WEBHOOK_BYTES = 1024 * 1024;

    /**
     * The largest message {@see fetch()} downloads: Resend accepts inbound mail
     * up to 40 MB, plus room for the base64 of the attachments in the raw file.
     */
    public const MAX_MESSAGE_BYTES = 56 * 1024 * 1024;

    /** Seconds for one download. It runs in a worker, not behind a screen. */
    public const DOWNLOAD_TIMEOUT = 120;

    /**
     * Resend's authentication words into the ones a message's
     * `Authentication-Results` would use. `unknown` is left out, which the
     * contract reads as unknown.
     *
     * @var array<string, string>
     */
    public const AUTH = [
        'pass' => 'pass',
        'fail' => 'fail',
        'gray' => 'none',
        'processing_failed' => 'temperror',
    ];

    private ResendReports $reports;

    /**
     * @param array<string, mixed>     $config   this plugin's own config block, for the API key
     * @param Http|null                $http     the API client; null is cURL
     * @param Download|null            $download the client for signed download links; null is cURL
     * @param (callable(): int)|null   $clock    the receiver's clock; a test hands over its own
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?Http $http = null,
        private readonly ?Download $download = null,
        ?callable $clock = null,
    ) {
        $this->reports = new ResendReports($clock);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Resend';
    }

    /**
     * The inbound webhook's own signing secret. Not the delivery-report one:
     * Resend mints a secret per webhook, and the two webhooks point at two
     * different addresses.
     *
     * @return list<string>
     */
    public function verificationKeys(): array
    {
        return [self::SECRET_KEY];
    }

    public function maxBytes(): int
    {
        return self::MAX_WEBHOOK_BYTES;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $secret = trim((string)($config[self::SECRET_KEY] ?? ''));
        if ($secret === '') {
            return Verdict::refused(
                'no Resend inbound signing secret is configured; the email.received webhook has a secret of its own'
            );
        }

        return $this->reports->verify(
            new WebhookRequest(
                $request->method,
                $request->path,
                $request->query,
                $request->headers,
                $request->body,
                $request->remoteAddress,
            ),
            [ResendReports::SECRET_KEY => $secret]
        );
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            $body = $request->json();
            if ($body === null || ($body !== [] && array_is_list($body))) {
                return InboundPayload::unreadable('the body was not a JSON object');
            }

            $type = \is_string($body['type'] ?? null) ? trim($body['type']) : '';
            if ($type !== self::EVENT) {
                return InboundPayload::nothing(sprintf('Resend reported "%s", which is not a received message', $type));
            }

            $data = \is_array($body['data'] ?? null) ? $body['data'] : null;
            if ($data === null) {
                return InboundPayload::unreadable('the email.received event carried no data object');
            }

            $id = \is_scalar($data['email_id'] ?? null) ? trim((string)$data['email_id']) : '';
            if ($id === '') {
                return InboundPayload::unreadable('the email.received event carried no email_id');
            }

            $attachments = \is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

            return InboundPayload::of([new InboundReference(self::KEY, $id, [
                'from' => self::text($data['from'] ?? null),
                'to' => self::strings($data['to'] ?? null),
                'cc' => self::strings($data['cc'] ?? null),
                'received_for' => self::strings($data['received_for'] ?? null),
                'subject' => self::text($data['subject'] ?? null),
                'message_id' => self::id(self::text($data['message_id'] ?? null)),
                'created_at' => self::text($data['created_at'] ?? null),
                'attachments' => \count($attachments),
            ])]);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('the email.received event could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Download the message behind a reference. Runs in the consumer's worker.
     *
     * @throws \RuntimeException when there is no key, Resend refuses, or a download fails; the worker retries
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        $key = trim((string)($config['api_key'] ?? ''));
        if ($key === '') {
            $key = trim((string)($this->config['api_key'] ?? ''));
        }
        if ($key === '') {
            throw new \RuntimeException('There is no Resend API key to download the received message with.');
        }

        $id = trim($ref->id);
        if ($id === '') {
            throw new \RuntimeException('The reference names no Resend email id.');
        }

        $email = $this->api('GET', '/emails/receiving/' . rawurlencode($id) . '?html_format=cid', $key, 'the received message');

        $overrides = self::overrides($email, $id);

        $raw = \is_array($email['raw'] ?? null) ? $email['raw'] : [];
        $rawUrl = self::text($raw['download_url'] ?? null);
        if ($rawUrl !== '') {
            return InboundMessage::fromMime($this->get($rawUrl, 'the raw message'), self::KEY, $overrides);
        }

        return $this->fromFields($email, $id, $key)->with($overrides);
    }

    public function instructions(string $webhookUrl): string
    {
        return 'In Resend, open Domains, open your domain and turn on Receiving, then add the MX record Resend shows to your DNS. '
            . 'If the domain already receives mail somewhere else, receive on a subdomain such as support.example.com instead, '
            . 'because mail only goes to the MX record with the lowest priority value. '
            . '(Every Resend account can also receive at its own <id>.resend.app address, shown under Emails, Receiving.) '
            . 'Then open Webhooks, press Add Webhook, paste ' . $webhookUrl . ', tick email.received and nothing else, and save. '
            . 'Open the new webhook and copy its signing secret, which begins with whsec_, into the Inbound signing secret field '
            . 'in the Email Resend plugin. It is a different secret from the delivery-report webhook\'s. '
            . 'Resend\'s webhook carries only the message\'s details and the message itself is downloaded afterwards with the '
            . 'plugin\'s API key, so that key needs Full access: a Sending access key cannot read received mail.';
    }

    // ------------------------------------------------------------- internals

    /**
     * What Resend knows that the raw message may not say: its own id, the
     * receiving server's authentication results and the addresses it received
     * the message for.
     *
     * @param  array<array-key, mixed> $email
     * @return array<string, mixed>
     */
    private static function overrides(array $email, string $id): array
    {
        $overrides = ['providerId' => self::text($email['id'] ?? null) ?: $id];

        $authentication = \is_array($email['authentication'] ?? null) ? $email['authentication'] : [];
        $auth = [];
        foreach (['spf', 'dkim', 'dmarc'] as $check) {
            $word = strtolower(self::text($authentication[$check] ?? null));
            if (isset(self::AUTH[$word])) {
                $auth[$check] = self::AUTH[$word];
            }
        }
        if ($auth !== []) {
            $overrides['auth'] = $auth;
        }

        // `received_for` is the `for` clause of the Received headers, which is
        // the envelope recipient as the receiving hop saw it: where a
        // support+token@ address survives when the visible To has lost it.
        $envelope = [];
        foreach (self::strings($email['received_for'] ?? null) as $address) {
            $address = strtolower(Address::parse($address)->email);
            if ($address !== '' && !\in_array($address, $envelope, true)) {
                $envelope[] = $address;
            }
        }
        if ($envelope !== []) {
            $overrides['envelopeTo'] = $envelope;
        }

        return $overrides;
    }

    /**
     * The message from Resend's JSON, for a message with no raw file.
     *
     * @param array<array-key, mixed> $email
     */
    private function fromFields(array $email, string $id, string $key): InboundMessage
    {
        $headers = [];
        $raw = \is_array($email['headers'] ?? null) ? $email['headers'] : [];
        foreach ($raw as $name => $value) {
            if (!\is_string($name) || $name === '') {
                continue;
            }
            foreach (\is_array($value) ? $value : [$value] as $one) {
                if (\is_scalar($one)) {
                    $headers[] = [$name, (string)$one];
                }
            }
        }
        $header = static function (string $name) use ($headers): string {
            foreach ($headers as [$key, $value]) {
                if (strtolower($key) === $name) {
                    return $value;
                }
            }

            return '';
        };

        $from = Address::parse($header('from') ?: self::text($email['from'] ?? null));

        $text = \is_string($email['text'] ?? null) ? $email['text'] : null;
        $html = \is_string($email['html'] ?? null) ? $email['html'] : null;

        $contentType = strtolower(trim(explode(';', $header('content-type'))[0]));
        if ($contentType === '') {
            $contentType = $html !== null && $text !== null
                ? 'multipart/alternative'
                : ($html !== null ? 'text/html' : 'text/plain');
        }

        $date = null;
        foreach ([$header('date'), self::text($email['created_at'] ?? null)] as $when) {
            $at = $when === '' ? false : strtotime($when);
            if ($at !== false) {
                $date = $at;
                break;
            }
        }

        $references = self::ids($header('references'));
        $returnPath = trim($header('return-path'), " \t<>");

        return new InboundMessage(
            raw: null,
            messageId: self::id(self::text($email['message_id'] ?? null) ?: $header('message-id')) ?: null,
            inReplyTo: self::ids($header('in-reply-to'))[0] ?? null,
            references: $references,
            from: $from,
            to: self::addresses($email['to'] ?? null),
            cc: self::addresses($email['cc'] ?? null),
            replyTo: self::addresses($email['reply_to'] ?? null),
            envelopeFrom: $header('return-path') === '' ? null : $returnPath,
            subject: self::text($email['subject'] ?? null),
            date: $date,
            text: $text,
            html: $html,
            headers: $headers,
            attachments: $this->attachments($email, $id, $key),
            receiver: self::KEY,
            providerId: $id,
            contentType: $contentType,
        );
    }

    /**
     * Every attachment, downloaded, when the message has any.
     *
     * @param  array<array-key, mixed> $email
     * @return list<InboundAttachment>
     */
    private function attachments(array $email, string $id, string $key): array
    {
        $listed = \is_array($email['attachments'] ?? null) ? $email['attachments'] : [];
        if ($listed === []) {
            return [];
        }

        $list = $this->api('GET', '/emails/receiving/' . rawurlencode($id) . '/attachments', $key, 'the attachment list');
        $rows = \is_array($list['data'] ?? null) ? $list['data'] : [];

        $out = [];
        $total = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = self::text($row['download_url'] ?? null);
            if ($url === '') {
                continue;
            }
            $name = self::filename(self::text($row['filename'] ?? null));
            $bytes = $this->get($url, 'the attachment ' . ($name === '' ? '(unnamed)' : $name));
            $total += \strlen($bytes);
            if ($total > self::MAX_MESSAGE_BYTES) {
                throw new \RuntimeException('The received message\'s attachments add up to more than the download limit.');
            }
            $cid = trim(self::text($row['content_id'] ?? null), " \t<>");

            $out[] = new InboundAttachment(
                filename: $name,
                contentType: strtolower(self::text($row['content_type'] ?? null)) ?: 'application/octet-stream',
                size: \strlen($bytes),
                contentId: $cid === '' ? null : $cid,
                inline: strtolower(self::text($row['content_disposition'] ?? null)) === 'inline',
                content: $bytes,
            );
        }

        return $out;
    }

    /**
     * One call to Resend's API, answered as its JSON object or thrown.
     *
     * @return array<array-key, mixed>
     */
    private function api(string $method, string $path, string $key, string $what): array
    {
        $answer = $this->client()->json($method, ResendApi::BASE . $path, null, ['Authorization' => 'Bearer ' . $key]);

        if ($answer['status'] === 0) {
            throw new \RuntimeException(sprintf(
                'Resend could not be reached for %s%s.',
                $what,
                $answer['error'] !== '' ? ': ' . $answer['error'] : ''
            ));
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            $message = trim((string)($body['message'] ?? $body['error'] ?? ''));
            throw new \RuntimeException(sprintf(
                'Resend answered %d for %s%s',
                $answer['status'],
                $what,
                $message !== '' ? ': ' . rtrim($message, '.') . '.' : '.'
            ));
        }

        return $body;
    }

    /** The bytes behind a signed link, or thrown. */
    private function get(string $url, string $what): string
    {
        $answer = $this->downloader()->download($url, self::MAX_MESSAGE_BYTES, self::DOWNLOAD_TIMEOUT);

        if ($answer['status'] < 200 || $answer['status'] >= 300 || $answer['body'] === null || $answer['body'] === '') {
            throw new \RuntimeException(sprintf(
                'Could not download %s from Resend (%s).',
                $what,
                $answer['error'] !== '' ? $answer['error'] : 'status ' . $answer['status']
            ));
        }

        return $answer['body'];
    }

    private function client(): Http
    {
        return $this->http ?? new CurlHttp();
    }

    private function downloader(): Download
    {
        if ($this->download !== null) {
            return $this->download;
        }

        return $this->http instanceof Download ? $this->http : new CurlHttp();
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * A list of strings out of a field documented as an array, reading a bare
     * string as a list of one.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [$value] as $one) {
            $one = self::text($one);
            if ($one !== '') {
                $out[] = $one;
            }
        }

        return $out;
    }

    /** @return list<Address> */
    private static function addresses(mixed $value): array
    {
        $out = [];
        foreach (self::strings($value) as $one) {
            foreach (Address::parseList($one) as $address) {
                $out[] = $address;
            }
        }

        return $out;
    }

    /** One message id without its angle brackets, lower-cased. */
    private static function id(string $value): string
    {
        return strtolower(trim($value, " \t\r\n<>"));
    }

    /**
     * Every message id in an In-Reply-To or References header.
     *
     * @return list<string>
     */
    private static function ids(string $value): array
    {
        if (preg_match_all('/<([^<>\s]+)>/', $value, $matches) > 0) {
            return array_values(array_map('strtolower', $matches[1]));
        }

        $id = self::id($value);

        return $id === '' || preg_match('/\s/', $id) === 1 ? [] : [$id];
    }

    /** A sender's filename reduced to a bare name: no directories, no control characters. */
    private static function filename(string $name): string
    {
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/', '', str_replace('\\', '/', $name));
        $name = basename($name);

        return $name === '.' || $name === '..' ? '' : $name;
    }
}
