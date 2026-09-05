<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * Resend's webhook, read.
 *
 * Documentation: `resend.com/docs/webhooks/introduction`, `/event-types` and
 * `/verify-webhooks-requests`. Read 2026-09-04 and checked again 2026-09-05.
 *
 * This came out of the KahunaCart Newsletter add-on, where it was
 * `classes/Providers/ResendParser.php`. Nothing about how it reads a payload has
 * changed; what changed is where it lives, which is the plugin that already
 * holds the API key.
 *
 * ## The envelope
 *
 * `{type, created_at, data}`, one event per request, all three always present.
 * `type` is dotted — `email.delivered`, `email.bounced` — and there are
 * nineteen of them, of which this reads five. `domain.*`, `contact.*`,
 * `suppression.*` and `email.received` are somebody else's business and are
 * answered with a note and no events.
 *
 * The two `created_at` fields are different things and it matters: the outer
 * one is when the *webhook event* was made, the inner one is when the *email*
 * was made. The event's own moment is the outer one, which is what a delivery
 * chart wants.
 *
 * ## Hard and soft
 *
 * `data.bounce.type` is Amazon's vocabulary, because Resend runs on SES:
 * `Permanent`, `Transient`, `Undetermined`. Permanent is hard and the other two
 * are not. Their own documentation warns that a `Transient` is sometimes only
 * an autoresponder, which is the reason a soft bounce here should suppress
 * nothing on its own.
 *
 * ## Correlation: `Message-ID` and nothing else
 *
 * `data.message_id` is documented as the RFC `Message-ID` header value, and it
 * arrives with the angle brackets on — {@see Event::of()} takes them off. A
 * store that mints its own `Message-ID` before a message leaves has a direct
 * join against the send row with nothing for a merchant to configure.
 *
 * There is no second path. **Resend echoes no headers on any webhook**, and
 * says so outright: "Webhooks do not include the email body, headers, or
 * attachments, only their metadata." So the send header is invisible here, and
 * {@see \Grav\Plugin\EmailResend\Provider\ResendProvider::capabilities()} says
 * so rather than leaving a merchant to find out.
 *
 * `data.tags` is the one merchant-settable map that does come back, and it is
 * read as a fallback for a store that has wired one up by hand — Resend's tag
 * keys are restricted to letters, digits, underscores and hyphens, so the key
 * is the send header's own name folded into that alphabet by {@see tag()}:
 * `X-Grav-Send-Id` becomes `grav-send-id`.
 *
 * ## Svix signatures
 *
 * Three headers — `svix-id`, `svix-timestamp`, `svix-signature` — over
 * `{id}.{timestamp}.{raw body}` with HMAC-SHA256, keyed with the base64-decoded
 * half of the `whsec_…` secret, compared base64-encoded. The white-labelled
 * spelling (`webhook-id` and friends) is accepted too, because Svix hands it to
 * their larger customers and a store on one of those would otherwise see every
 * event refused.
 *
 * The timestamp is checked against the receiver's clock with a five-minute
 * tolerance, which is what Svix's own libraries use. Without it, a signature
 * captured once is a signature that works forever.
 */
final class ResendReports implements DeliveryReports
{
    /** The config key the signing secret is kept under. */
    public const SECRET_KEY = 'signing_secret';

    /** How far out of step with Resend's clock a request may be. */
    public const TOLERANCE = 300;

    /**
     * The Svix header names, in both spellings Svix issues.
     *
     * @var array<string, list<string>>
     */
    public const HEADERS = [
        'id' => ['svix-id', 'webhook-id'],
        'timestamp' => ['svix-timestamp', 'webhook-timestamp'],
        'signature' => ['svix-signature', 'webhook-signature'],
    ];

    /**
     * Their event names to ours.
     *
     * Everything else Resend sends — `email.sent`, `email.scheduled`,
     * `email.delivery_delayed`, `email.received`, and the whole of `domain.*`,
     * `contact.*` and `suppression.*` — is answered with a note and no events
     * rather than a refusal, because a merchant who ticked every box in
     * Resend's dashboard has not made a mistake.
     *
     * `email.delivery_delayed` is deliberately not mapped, and is the one worth
     * saying out loud: a delay is Resend telling you the recipient's mailbox
     * was full or their server was busy, and it is followed either by a
     * delivery or by a bounce. Acting on it would suppress addresses that are
     * about to receive their mail.
     *
     * `email.failed` and `email.suppressed` are both {@see Event::DROPPED}:
     * Resend refusing to send at all, which is exactly what that word is for.
     * `email.suppressed` is Resend saying the address was already on its own
     * suppression list, and `email.failed` is Resend saying it could not send
     * for a reason of its own — an unverified domain, a quota. Neither was ever
     * handed to a receiving server, so neither is a bounce, and whatever
     * records these events decides for itself what a drop means for a
     * subscriber. `email.failed` in particular is often the store's own problem
     * rather than the recipient's, and its `reason` says which.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'email.delivered' => Event::DELIVERED,
        'email.bounced' => Event::BOUNCED,
        'email.complained' => Event::COMPLAINED,
        'email.opened' => Event::OPENED,
        'email.clicked' => Event::CLICKED,
        'email.failed' => Event::DROPPED,
        'email.suppressed' => Event::DROPPED,
    ];

    /** @var (callable(): int) */
    private $clock;

    /** @param (callable(): int)|null $clock the receiver's clock; a test hands over its own */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return list<string> */
    public function events(): array
    {
        return array_values(array_unique(array_values(self::TYPES)));
    }

    /**
     * The signing secret, and nothing else.
     *
     * Resend signs every webhook through Svix, so unlike the providers that
     * sign nothing this one has a credential of its own and it lives in this
     * plugin's config beside the sending key.
     *
     * @return list<string>
     */
    public function verificationKeys(): array
    {
        return [self::SECRET_KEY];
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $secret = trim((string)($config[self::SECRET_KEY] ?? ''));
        if ($secret === '') {
            return Verdict::refused('no Resend signing secret is configured');
        }

        $id = self::headerOf($request, 'id');
        $timestamp = self::headerOf($request, 'timestamp');
        $signature = self::headerOf($request, 'signature');

        if ($id === '' || $timestamp === '' || $signature === '') {
            return Verdict::refused('the Svix signature headers were missing');
        }

        if (!self::fresh($timestamp, ($this->clock)())) {
            return Verdict::refused('the Svix timestamp was outside the tolerance');
        }

        $key = self::key($secret);
        if ($key === '') {
            return Verdict::refused('the Resend signing secret is not a whsec_ value');
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $id . '.' . $timestamp . '.' . $request->body,
            $key,
            true
        ));

        // The header is a space-separated list, each entry `v1,<base64>`, so a
        // secret can be rotated without a gap. Every entry is compared, and
        // every comparison is constant time — one that returned on the first
        // match would leak how far down the list the right one was.
        $matched = false;
        foreach (explode(' ', $signature) as $candidate) {
            $parts = explode(',', trim($candidate), 2);
            if (\count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }

            $matched = hash_equals($expected, $parts[1]) || $matched;
        }

        return $matched ? Verdict::verified() : Verdict::refused('the Svix signature did not match');
    }

    public function parse(WebhookRequest $request): Payload
    {
        // `WebhookRequest::json()` deliberately answers a list as well as an
        // object, because one of the six providers posts one. Resend never
        // does, and a list here is somebody else's payload arriving at this
        // address rather than an event with nothing in it.
        $body = $request->json();
        if ($body === null || ($body !== [] && array_is_list($body))) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $name = trim((string)($body['type'] ?? ''));
        $type = self::TYPES[$name] ?? null;

        if ($type === null) {
            return Payload::nothing(sprintf('Resend reported "%s", which this store does not act on', $name));
        }

        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
        $bounce = \is_array($data['bounce'] ?? null) ? $data['bounce'] : [];

        $hard = null;
        if ($type === Event::BOUNCED) {
            $hard = strtolower(trim((string)($bounce['type'] ?? ''))) === 'permanent';
        }

        // A drop carries its own explanation under its own key, and the two are
        // shaped differently: `failed.reason` is a machine word and
        // `suppressed.message` is a sentence Resend wrote for a person.
        if ($type === Event::DROPPED) {
            $bounce = self::refusal($data);
        }

        return Payload::of([Event::of(
            $type,
            $hard,
            self::recipient($data),
            (string)($data['message_id'] ?? ''),
            (string)($data['email_id'] ?? ''),
            Moment::parse($body['created_at'] ?? null) ?? 0,
            self::reason($bounce, $type),
            self::sendId($data),
        )]);
    }

    /**
     * The name the Email plugin answers, which is `X-Grav-Send-Id` unless the
     * site says otherwise.
     *
     * Resend never sends it back — see the class note — and it is answered
     * anyway because the contract asks for a name and because a store setting
     * the matching tag needs to know which one.
     */
    public function sendHeader(): string
    {
        return SendHeader::name();
    }

    /**
     * The send header's name as a Resend tag key.
     *
     * Resend allows letters, digits, underscores and hyphens in a tag key and
     * nothing else, so the header's name is lower-cased, its leading `X-`
     * dropped and everything outside that alphabet turned into an underscore.
     * `X-Grav-Send-Id` becomes `grav-send-id`.
     */
    public static function tag(): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9_\-]/', '_', SendHeader::metadataKey()));
    }

    // ------------------------------------------------------------- internals

    /**
     * Who it was about.
     *
     * `to` is a list in every documented sample and a campaign sends one
     * message per person, so the list is one long. A bare string is read as
     * well, because an API that has answered both ways over the years will
     * probably do it again.
     *
     * @param array<string, mixed> $data
     */
    private static function recipient(array $data): string
    {
        $to = $data['to'] ?? null;

        if (\is_array($to)) {
            return (string)($to[0] ?? '');
        }

        return \is_scalar($to) ? (string)$to : '';
    }

    /**
     * The provider's own words about why.
     *
     * A bounce carries both a `subType` — SES's own classification, `General`,
     * `Suppressed`, `MailboxFull` — and a `message`, which is the sentence
     * Resend writes for a person. Both are worth keeping and they read best
     * together, because the subtype is the part a merchant can act on and the
     * message is the part they can understand.
     *
     * @param array<string, mixed> $bounce
     */
    private static function reason(array $bounce, string $type): ?string
    {
        $message = trim((string)($bounce['message'] ?? ''));
        $subType = trim((string)($bounce['subType'] ?? ''));

        if ($message !== '') {
            return $subType === '' ? $message : $subType . ': ' . $message;
        }

        if ($subType !== '') {
            return $subType;
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }

    /**
     * Resend's own words about a message it refused to send, in the two shapes
     * it writes them.
     *
     * `email.failed` carries `failed.reason`, which is a machine word like
     * `reached_daily_quota` and is worth passing on as it is; `email.suppressed`
     * carries `suppressed.message` and `suppressed.type`. Both are turned into
     * the pair {@see reason()} already knows how to read.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function refusal(array $data): array
    {
        $suppressed = \is_array($data['suppressed'] ?? null) ? $data['suppressed'] : null;

        if ($suppressed !== null) {
            return [
                'message' => (string)($suppressed['message'] ?? ''),
                'subType' => (string)($suppressed['type'] ?? ''),
            ];
        }

        $failed = \is_array($data['failed'] ?? null) ? $data['failed'] : [];

        return ['message' => (string)($failed['reason'] ?? '')];
    }

    /**
     * The send id out of the one merchant-settable map Resend echoes.
     *
     * Not a header: Resend returns no headers on any webhook. `data.tags` is
     * what does come back, and a store that set the {@see tag()} tag on the
     * send gets the id here. A store that did not gets null and correlates on
     * the `Message-ID`, which needs nothing configured at all.
     *
     * @param array<string, mixed> $data
     */
    private static function sendId(array $data): ?string
    {
        $tags = $data['tags'] ?? null;
        if (!\is_array($tags)) {
            return null;
        }

        return SendHeader::idIn($tags, self::tag());
    }

    /** One of the three headers, under either spelling. */
    private static function headerOf(WebhookRequest $request, string $which): string
    {
        foreach (self::HEADERS[$which] as $name) {
            $value = trim($request->header($name));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function fresh(string $timestamp, int $now): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        return abs($now - (int)$timestamp) <= self::TOLERANCE;
    }

    /**
     * The HMAC key: what is after `whsec_`, base64-decoded.
     *
     * A secret without the prefix is accepted as already being the base64 half,
     * because Svix's own dashboard shows it both ways depending on where you
     * copy it from and a merchant should not have to know which.
     */
    private static function key(string $secret): string
    {
        $body = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($body, true);

        return $decoded === false ? '' : $decoded;
    }
}
