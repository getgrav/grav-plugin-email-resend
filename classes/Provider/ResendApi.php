<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * The things this plugin asks Resend about the account it already has a key
 * for.
 *
 * Creating the store's delivery webhook, and reading what a sending domain's
 * DNS is meant to say on the account. Both go through the same key the merchant
 * pasted in for sending, and neither is stored here: the key is handed over per
 * call.
 *
 * Documentation: `resend.com/docs/api-reference/webhooks/create-webhook`,
 * `/list-webhooks`, `/update-webhook`, `/domains/list-domains` and
 * `/domains/get-domain`, read 2026-09-05.
 *
 * ## The webhook call
 *
 * `POST /webhooks` with `{endpoint, events}`, answering
 * `{object, id, signing_secret}`. The signing secret is the whole reason this
 * button is worth having: Resend hands it over once, in that answer, and never
 * again through the API. A merchant doing it by hand can still read it off the
 * webhook's page in the dashboard, which is the fallback when the config file
 * turns out not to be writable.
 *
 * `PATCH /webhooks/{id}` with `{endpoint, events, status}` is the other half of
 * it, and the reason it is here is a store whose secret has changed: the
 * webhook Resend holds is then posting at an address that answers 404. Moving
 * that webhook is better than making a second one, because the signing secret
 * the store already has belongs to it and an update mints no new one.
 *
 * ## What it does not do
 *
 * It does not delete or disable a webhook. A merchant who wants one gone should
 * remove it in Resend's own dashboard, where they can see what else is pointed
 * at it — a plugin removing a webhook it did not create is exactly the sort of
 * thing that takes a store's other integration down at four on a Friday.
 */
final class ResendApi
{
    /** Resend's API base. There are no regional bases; the region is a property of a domain. */
    public const BASE = 'https://api.resend.com';

    /**
     * The events this provider reports, in Resend's spelling.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'email.delivered',
        'email.bounced',
        'email.complained',
        'email.opened',
        'email.clicked',
        'email.failed',
        'email.suppressed',
    ];

    /**
     * One answer per domain per request.
     *
     * Both the SPF check and the DKIM check on a deliverability screen want the
     * same answer in the same run, and neither should cost a second round trip
     * to somebody else's API.
     *
     * @var array<string, array{selectors: list<string>, return_paths: list<string>}>
     */
    private array $domains = [];

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Create the store's webhook, or say plainly why it could not be.
     *
     * @param  list<string> $events Resend's own event names
     * @return array{ok: bool, id: string|null, secret: string, message: string}
     */
    public function createWebhook(string $apiKey, string $url, array $events = self::EVENTS): array
    {
        $apiKey = trim($apiKey);
        $events = $events === [] ? self::EVENTS : $events;

        if ($apiKey === '') {
            return self::no('There is no Resend API key to set the webhook up with.');
        }

        if (trim($url) === '') {
            return self::no('There is no webhook address to register yet.');
        }

        $answer = $this->http->json('POST', self::BASE . '/webhooks', [
            'endpoint' => $url,
            'events' => array_values($events),
        ], self::auth($apiKey));

        if ($answer['status'] === 0) {
            return self::no($answer['error'] !== ''
                ? 'Resend could not be reached: ' . $answer['error'] . '.'
                : 'Resend could not be reached.');
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            return self::no(self::refusal($body, $answer['status']));
        }

        $id = trim((string)($body['id'] ?? ''));
        $secret = trim((string)($body['signing_secret'] ?? ''));

        return [
            'ok' => true,
            'id' => $id === '' ? null : $id,
            'secret' => $secret,
            'message' => 'The webhook was created in Resend.',
        ];
    }

    /**
     * Point an existing webhook at a new address, keeping the events it reports.
     *
     * This is what `Set up` does when the store's secret has changed since the
     * webhook was made: the old address answers 404, and Resend has no upsert.
     * Editing the one that is there is also the only move that keeps the
     * signing secret working, since Resend mints one per webhook and hands it
     * over only on the create call — a second webhook would arrive signed with
     * a secret the store has never seen.
     *
     * `status` is sent as `enabled` because a webhook Resend disabled after a
     * run of failed posts — which is exactly what a dead address produces — is
     * still disabled after an address change otherwise.
     *
     * @param  list<string> $events Resend's own event names
     * @return array{ok: bool, id: string|null, secret: string, message: string}
     */
    public function updateWebhook(string $apiKey, string $id, string $url, array $events = self::EVENTS): array
    {
        $apiKey = trim($apiKey);
        $id = trim($id);
        $events = $events === [] ? self::EVENTS : $events;

        if ($apiKey === '') {
            return self::no('There is no Resend API key to set the webhook up with.');
        }

        if ($id === '') {
            return self::no('There is no webhook to update.');
        }

        if (trim($url) === '') {
            return self::no('There is no webhook address to register yet.');
        }

        $answer = $this->http->json('PATCH', self::BASE . '/webhooks/' . rawurlencode($id), [
            'endpoint' => $url,
            'events' => array_values($events),
            'status' => 'enabled',
        ], self::auth($apiKey));

        if ($answer['status'] === 0) {
            return self::no($answer['error'] !== ''
                ? 'Resend could not be reached: ' . $answer['error'] . '.'
                : 'Resend could not be reached.');
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            return self::no(self::refusal($body, $answer['status']));
        }

        return [
            'ok' => true,
            'id' => $id,
            // Resend answers an update without a signing secret; it mints one
            // per webhook and shows it only on the create call. The store keeps
            // the one it already has, which still belongs to this webhook.
            'secret' => '',
            'message' => 'The webhook in Resend now points at this address.',
        ];
    }

    /**
     * The account's webhooks, or null when they could not be read at all.
     *
     * Why they could not be read is deliberately not answered: a key that
     * cannot list webhooks is about to be refused by the create call in
     * Resend's own words, and two messages about one permission is one message
     * too many. Read once per press and matched twice by the setup, against the
     * exact address and then against the endpoint, so Resend is asked one
     * question.
     *
     * @return list<array<string, mixed>>|null
     */
    public function webhooks(string $apiKey): ?array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return null;
        }

        $answer = $this->http->json('GET', self::BASE . '/webhooks', null, self::auth($apiKey));

        if ($answer['status'] < 200 || $answer['status'] >= 300 || !\is_array($answer['body'] ?? null)) {
            return null;
        }

        return self::rows($answer['body']);
    }

    /**
     * The id of a webhook already pointing at this address, or null.
     *
     * This is what keeps a second press of the button from leaving a store with
     * two webhooks posting the same events at the same place. It is also the
     * one thing here that cannot be fixed up afterwards: Resend mints a new
     * signing secret per webhook, so two webhooks at one address means half the
     * events arriving signed with a secret the store never saw.
     *
     * A key that cannot list webhooks answers null, deliberately: the create
     * call that follows will fail with Resend's own words about the permission,
     * and that is the more useful of the two messages.
     */
    public function webhookAt(string $apiKey, string $url): ?string
    {
        $webhooks = $this->webhooks($apiKey);

        return $webhooks === null ? null : self::idAt($webhooks, $url);
    }

    /**
     * The id of the webhook in this list pointing at exactly this address, or
     * null; an empty string where one is there and Resend did not name it.
     *
     * @param list<array<string, mixed>> $webhooks what {@see webhooks()} answered
     */
    public static function idAt(array $webhooks, string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        foreach ($webhooks as $row) {
            $endpoint = self::endpointIn($row);

            if ($endpoint !== '' && rtrim($endpoint, '/') === rtrim($url, '/')) {
                // An empty string still means "one is already there", which is
                // the question being asked. The caller shows a shorter sentence.
                return trim((string)($row['id'] ?? ''));
            }
        }

        return null;
    }

    /**
     * The id of a webhook pointing somewhere under this prefix, or null.
     *
     * A store's webhook address is its endpoint followed by a secret, so a
     * webhook whose address starts with the endpoint but is not the whole
     * address is this store's own registered against an older secret. That is
     * the one worth updating rather than adding beside.
     *
     * Only a webhook Resend named is answered, because an update needs the id.
     *
     * @param list<array<string, mixed>> $webhooks what {@see webhooks()} answered
     */
    public static function idUnder(array $webhooks, string $prefix): ?string
    {
        $prefix = trim($prefix);

        if ($prefix === '') {
            return null;
        }

        foreach ($webhooks as $row) {
            $endpoint = self::endpointIn($row);

            if ($endpoint === '' || !str_starts_with($endpoint, $prefix)) {
                continue;
            }

            $id = trim((string)($row['id'] ?? ''));

            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }


    /**
     * What Resend says one sending domain's DNS should be: its DKIM selectors
     * and its return-path host.
     *
     * Resend publishes DKIM as a TXT record rather than a CNAME, so there is no
     * zone convention to check a selector against and the only honest answer
     * about a selector is the one the account itself gives. That makes this
     * lookup worth rather more here than it is for a provider whose selectors
     * CNAME into a zone of their own.
     *
     * Three things keep it honest.
     *
     * **It never throws.** A key that has been revoked, an API that is down, a
     * network with no route out: all of them answer the empty pair and the
     * caller falls back to asking the merchant. A deliverability screen that
     * 500s because a third party is having an outage is worse than one that
     * says it could not find out.
     *
     * **It is bounded.** Two calls at the outside — the list, then the one
     * domain — through {@see CurlHttp}'s own timeouts, and once per domain per
     * request.
     *
     * **It sends the key and nothing else.** No addresses, no campaign data, no
     * store name. The request is the account's own key asking about the
     * account's own domains.
     *
     * @return array{selectors: list<string>, return_paths: list<string>}
     */
    public function domainFacts(string $apiKey, string $domain): array
    {
        $empty = ['selectors' => [], 'return_paths' => []];

        $apiKey = trim($apiKey);
        $domain = self::normalise($domain);

        if ($apiKey === '' || $domain === '') {
            return $empty;
        }

        if (isset($this->domains[$domain])) {
            return $this->domains[$domain];
        }

        try {
            $row = $this->domainRow($apiKey, $domain);
        } catch (\Throwable) {
            // The contract says a lookup never throws, and an HTTP client that
            // does anyway is still an unanswered question rather than a broken
            // screen. Remembered like any other answer, so a screen drawing two
            // checks does not wait for the same failure twice.
            return $this->domains[$domain] = $empty;
        }

        return $this->domains[$domain] = $row === null ? $empty : self::factsIn($row, $domain);
    }

    /**
     * The two facts about one domain in Resend's answer.
     *
     * Their `records` list is the whole of it: a `DKIM` entry whose `name` is
     * `<selector>._domainkey` and whose type is TXT, and an `SPF` entry whose
     * `name` is the sending subdomain — `send` by default — and whose MX value
     * points at `feedback-smtp.<region>.amazonses.com`. The names are relative
     * to the domain, so `send` is written out as `send.example.com` here, which
     * is what a DNS check is going to look up.
     *
     * @param  array<string, mixed> $row one domain, as Resend answers it
     * @return array{selectors: list<string>, return_paths: list<string>}
     */
    public static function factsIn(array $row, string $domain): array
    {
        $domain = self::normalise($domain);
        $records = $row['records'] ?? [];

        if (!\is_array($records)) {
            return ['selectors' => [], 'return_paths' => []];
        }

        $selectors = [];
        $returnPaths = [];

        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }

            $name = self::normalise((string)($record['name'] ?? ''));
            $type = strtoupper(trim((string)($record['type'] ?? '')));
            $value = self::normalise((string)($record['value'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (preg_match('/^([a-z0-9_-]+)\._domainkey\b/', $name, $matches) === 1) {
                $selectors[] = $matches[1];

                continue;
            }

            // The return path is the subdomain whose MX points into Amazon's
            // feedback host. Matching on that rather than on the literal name
            // `send` is what keeps this working for an account that chose a
            // different sending subdomain.
            if ($type === 'MX' && str_ends_with($value, ResendProvider::RETURN_PATH_ZONE)) {
                $returnPaths[] = self::absolute($name, $domain);
            }
        }

        return [
            'selectors' => array_values(array_unique($selectors)),
            'return_paths' => array_values(array_unique($returnPaths)),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * One domain as Resend has it, found by name, with its records.
     *
     * Two calls, because the list endpoint does not carry the records: `GET
     * /domains` to find the id and `GET /domains/{id}` for the DNS. A domain
     * the account has never heard of costs one call and answers null.
     *
     * @return array<string, mixed>|null
     */
    private function domainRow(string $apiKey, string $domain): ?array
    {
        $list = $this->http->json('GET', self::BASE . '/domains', null, self::auth($apiKey));

        if ($list['status'] < 200 || $list['status'] >= 300 || !\is_array($list['body'] ?? null)) {
            return null;
        }

        $id = '';
        foreach (self::rows($list['body']) as $row) {
            $name = self::normalise((string)($row['name'] ?? ''));

            if ($name !== '' && self::aligns($domain, $name)) {
                $id = trim((string)($row['id'] ?? ''));

                if ($name === $domain) {
                    break;
                }
            }
        }

        if ($id === '') {
            return null;
        }

        $one = $this->http->json('GET', self::BASE . '/domains/' . rawurlencode($id), null, self::auth($apiKey));

        if ($one['status'] < 200 || $one['status'] >= 300 || !\is_array($one['body'] ?? null)) {
            return null;
        }

        return $one['body'];
    }

    /**
     * The rows in one of Resend's list answers.
     *
     * Their list endpoints answer `{object: "list", data: [...]}`. A bare list
     * is read as well, because the SDK examples show one and it costs two lines
     * to not care which arrives.
     *
     * @param  array<array-key, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function rows(array $body): array
    {
        $rows = \is_array($body['data'] ?? null) ? $body['data'] : $body;

        $out = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The address one of Resend's webhook rows is pointed at.
     *
     * `endpoint` is what their reference calls it; the other two are read as
     * well because their SDK examples have shown both and it costs one line to
     * not care which arrives.
     *
     * @param array<string, mixed> $row
     */
    private static function endpointIn(array $row): string
    {
        return trim((string)($row['endpoint'] ?? $row['endpoint_url'] ?? $row['url'] ?? ''));
    }

    /**
     * Resend's own words about a refusal, made into a sentence.
     *
     * Their errors are `{"statusCode": 401, "name": "missing_api_key",
     * "message": "…"}` and the message is written for a person, so it is used
     * as it stands wherever there is one. A refusal with no message at all is
     * the status number and nothing else, which is unhelpful and honest.
     *
     * @param array<array-key, mixed> $body
     */
    private static function refusal(array $body, int $status): string
    {
        $message = trim((string)($body['message'] ?? $body['error'] ?? ''));

        if ($message === '') {
            return sprintf('Resend answered %d and said nothing more.', $status);
        }

        $message = ucfirst($message);

        return str_ends_with($message, '.') ? $message : $message . '.';
    }

    /**
     * @return array{ok: false, id: null, secret: string, message: string}
     */
    private static function no(string $message): array
    {
        return ['ok' => false, 'id' => null, 'secret' => '', 'message' => $message];
    }

    /** @return array<string, string> */
    private static function auth(string $apiKey): array
    {
        return ['Authorization' => 'Bearer ' . $apiKey];
    }

    /** A record name written out in full, since Resend's are relative to the domain. */
    private static function absolute(string $name, string $domain): string
    {
        if ($name === '' || $domain === '') {
            return $name;
        }

        return $name === $domain || str_ends_with($name, '.' . $domain)
            ? $name
            : $name . '.' . $domain;
    }

    /** A host with its case, its trailing dot and its surrounding space taken off. */
    private static function normalise(string $value): string
    {
        return strtolower(trim(trim($value), " \t\n\r\0\x0B.\""));
    }

    /**
     * Whether two names are the same organisation, in the relaxed sense: equal,
     * or one a subdomain of the other.
     *
     * A store sending as `news@mail.example.com` whose Resend account lists
     * `example.com` is the same domain for this purpose, and refusing to read
     * its selector because the strings differ would be a check that fails on
     * every store that uses a sending subdomain.
     */
    private static function aligns(string $a, string $b): bool
    {
        $a = self::normalise($a);
        $b = self::normalise($b);

        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }
}
