<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * "Set up in Resend", which is the one button a merchant should have to press.
 *
 * A driver sets itself up from the key that was already pasted in for sending,
 * with manual steps only as the fallback. Resend's API allows it:
 * `POST /webhooks` with an address and a list of events creates the webhook and
 * hands back the signing secret in the same answer.
 *
 * ## The secret is the whole point
 *
 * Resend shows the signing secret once, in the answer to the create call, and
 * never again through the API. So it is saved straight into this plugin's own
 * config through the closure the plugin file hands in, rather than printed for
 * somebody to copy across. Where that cannot be done — a config directory that
 * is not writable, a site running from a read-only deploy — the merchant is
 * told to read the secret off the webhook's own page in Resend's dashboard,
 * which is where it stays visible. What is never done is putting the secret
 * itself into a message on a screen.
 *
 * ## Pressing it twice
 *
 * Resend's create call has no upsert, and two webhooks at one address is worse
 * than a duplicate here than it is elsewhere: each one gets its own signing
 * secret, so half the events would arrive signed with a secret the store never
 * saw and would be refused with nothing saying why. So {@see create()} asks for
 * the account's webhooks first and stops when one is already pointing at the
 * same address.
 */
final class ResendSetup implements WebhookSetup
{
    /**
     * What a key must be allowed to do, in Resend's own vocabulary.
     *
     * A constant rather than a sentence built somewhere, because it is the same
     * every time and it is shown both before the button is pressed and after a
     * refusal that mentions a permission.
     */
    public const PERMISSIONS = 'The API key needs Full access. In Resend, open API Keys, and either create a new '
        . 'key with Full access or check the one you are using. A key with Sending access sends mail perfectly '
        . 'well and cannot create the webhook that reports what happened to it.';

    /**
     * The contract's event words to Resend's own.
     *
     * A list each, because `dropped` is two of Resend's: `email.failed` is
     * Resend saying it could not send, and `email.suppressed` is Resend saying
     * the address was already on its own list. Both mean nothing was handed to
     * a receiving server.
     *
     * @var array<string, list<string>>
     */
    private const EVENTS = [
        Event::DELIVERED => ['email.delivered'],
        Event::BOUNCED => ['email.bounced'],
        Event::COMPLAINED => ['email.complained'],
        Event::OPENED => ['email.opened'],
        Event::CLICKED => ['email.clicked'],
        Event::DROPPED => ['email.failed', 'email.suppressed'],
    ];

    /**
     * @param array<string, mixed>                 $config    this plugin's own configuration
     * @param (\Closure(string $secret): bool)|null $saveSecret writes the signing
     *        secret back into this plugin's config; null means nothing can, and
     *        the merchant is told where to read it instead
     */
    public function __construct(
        private readonly ResendApi $api,
        private readonly array $config = [],
        private readonly ?\Closure $saveSecret = null,
    ) {
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $key = self::keyIn($config);
        $key = $key !== '' ? $key : self::keyIn($this->config);

        if ($key === '') {
            return SetupResult::failed(
                'There is no Resend API key to set the webhook up with. Paste one into the Email Resend plugin '
                . 'first — it is the same key the store sends with.'
            );
        }

        if (trim($url) === '') {
            return SetupResult::failed('There is no webhook address to register yet.');
        }

        $already = $this->api->webhookAt($key, $url);
        if ($already !== null) {
            return SetupResult::ok(
                'Resend already has a webhook at this address, so nothing was added. If delivery events are '
                . 'still being refused, the signing secret here does not match that webhook: open it in Resend '
                . 'and copy its signing secret into this plugin.',
                $already === '' ? null : $already
            );
        }

        $answer = $this->api->createWebhook($key, $url, self::theirNames($events));

        if (!$answer['ok']) {
            return SetupResult::failed(self::sentence($answer['message']));
        }

        return SetupResult::ok($this->kept($answer['secret']), $answer['id']);
    }

    public function permissionsNeeded(): string
    {
        return self::PERMISSIONS;
    }

    // ------------------------------------------------------------- internals

    /**
     * What to say once the webhook exists, which depends entirely on whether
     * the secret was kept.
     *
     * A webhook without its secret is not half set up: it is a webhook whose
     * every event will be refused. So the sentence for that case names the
     * screen the secret is on rather than saying it worked.
     */
    private function kept(string $secret): string
    {
        if ($secret === '') {
            return 'The webhook was created in Resend, but its answer carried no signing secret. Open the webhook '
                . 'in Resend, copy its signing secret — it begins with whsec_ — and paste it into the Webhook '
                . 'signing secret field in this plugin, or delivery events will be refused.';
        }

        $saved = false;
        if ($this->saveSecret !== null) {
            try {
                $saved = ($this->saveSecret)($secret) === true;
            } catch (\Throwable) {
                $saved = false;
            }
        }

        return $saved
            ? 'The webhook was created in Resend and its signing secret was saved, so delivery events will be '
                . 'checked from now on.'
            : 'The webhook was created in Resend, but its signing secret could not be saved here — the config '
                . 'file may not be writable. Open the webhook in Resend, copy its signing secret — it begins '
                . 'with whsec_ — and paste it into the Webhook signing secret field in this plugin, or delivery '
                . 'events will be refused.';
    }

    /**
     * Resend's names for the events the caller asked for.
     *
     * An event this provider cannot report is dropped rather than refused: the
     * contract says a provider maps what it can and ignores the rest, so a
     * caller asking for a word Resend has never heard of gets a webhook for the
     * ones it does have rather than an error. Asking for nothing at all
     * registers all seven, because a webhook for no events is a webhook that
     * never fires.
     *
     * @param  list<string> $events
     * @return list<string>
     */
    private static function theirNames(array $events): array
    {
        $names = [];

        foreach ($events as $event) {
            foreach (self::EVENTS[strtolower(trim((string)$event))] ?? [] as $name) {
                if (!\in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names === [] ? ResendApi::EVENTS : $names;
    }

    /** @param array<string, mixed> $config */
    private static function keyIn(array $config): string
    {
        return trim((string)($config['api_key'] ?? ''));
    }

    /**
     * Resend's own words, with the permission sentence added where it is the
     * likely answer.
     *
     * Their refusals are written for a person — "This API key is restricted to
     * only send emails" — and that sentence on its own still leaves a merchant
     * wondering which box to tick.
     */
    private static function sentence(string $message): string
    {
        $message = trim($message);
        $message = $message === '' ? 'Resend refused it.' : ucfirst($message);

        if (!str_ends_with($message, '.')) {
            $message .= '.';
        }

        foreach (['permission', 'restricted', 'not allowed', 'unauthorized', 'access'] as $word) {
            if (stripos($message, $word) !== false) {
                return $message . ' ' . self::PERMISSIONS;
            }
        }

        return $message;
    }
}
