<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * Everything Resend knows about itself, answered by Resend's own plugin.
 *
 * Registered on the Email plugin's `onEmailProviders` event by
 * {@see \Grav\Plugin\EmailResendPlugin}. Nothing else on a site carries a
 * Resend parser, a table of Resend's DNS, or a branch for what Resend does to a
 * header — they ask.
 *
 * This class is built while a settings screen is being drawn, so it does no I/O
 * of its own. The two things here that talk to Resend are
 * {@see ResendSetup::create()}, which is behind a button, and the closure on
 * {@see domain()}, which the caller decides when to run.
 *
 * Not final, for one reason: {@see ResendInboundProvider} is this class plus
 * the Email plugin's `InboundCapable` interface, and the plugin registers that
 * one instead when the Email plugin is new enough to have the interface. This
 * class names no inbound type in its declaration, so it still loads beside an
 * Email plugin from before inbound mail.
 */
class ResendProvider implements Provider
{
    /** The engine this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'resend';

    /**
     * The host an SPF record has to end up sending people to, at any depth.
     *
     * Resend does not ask for anything on the domain's own SPF record. Its
     * sending subdomain, `send.example.com`, is a CNAME to
     * `send.forge.rmta.net`, which carries the SPF itself, and that is where a
     * receiver checks, because it is the return path. This host is what an
     * `include:` would have to name for the apex record to pass on its own,
     * which nobody needs to do: the return path check below is the one that
     * normally answers.
     */
    public const SPF_INCLUDE = 'send.forge.rmta.net';

    /**
     * Null, and deliberately.
     *
     * Resend publishes DKIM as a **TXT** record at `resend._domainkey`, holding
     * the public key itself, rather than as a CNAME into a zone of theirs. So
     * there is no zone for a selector to point into and nothing here to check a
     * name against. The lookup on {@see domain()} is the way out of that, and
     * for this provider it is the only way there is.
     */
    public const DKIM_ZONE = null;

    /** The zone a return path ends up in: `send.example.com` is a CNAME to `send.forge.rmta.net`. */
    public const RETURN_PATH_ZONE = 'rmta.net';

    /**
     * Where a return path pointed when Resend sent through Amazon SES: an MX
     * record to `feedback-smtp.<region>.amazonses.com` beside
     * `v=spf1 include:amazonses.com ~all`. Domains added back then can still
     * answer that way, so the account lookup still recognises it.
     */
    public const LEGACY_RETURN_PATH_ZONE = 'amazonses.com';

    /** The selector Resend publishes on every domain today. Read from the account rather than assumed. */
    public const SELECTOR = 'resend';

    /**
     * Resend's own test address: accepted, delivered to nobody, and reported
     * as delivered, so it produces the webhook event a store's test waits for.
     *
     * "To test that your emails are being successfully delivered, you can send
     * an email to: delivered@resend.dev", and the test addresses "enable the
     * safe use of Resend's Dashboard, Webhooks, and API":
     * https://resend.com/docs/dashboard/emails/send-test-emails. They count
     * against the account's sending quota like any other message.
     */
    public const TEST_RECIPIENT = 'delivered@resend.dev';

    /** The language key {@see instructions()} looks for before its English. */
    public const INSTRUCTIONS_KEY = 'PLUGIN_EMAIL_RESEND.PROVIDER_INSTRUCTIONS';

    /** The language key {@see capabilities()} looks for before its English. */
    public const ECHO_NOTE_KEY = 'PLUGIN_EMAIL_RESEND.ECHO_NOTE';

    private ?ResendReports $reports = null;

    private ?ResendSetup $setup = null;

    private ?ResendApi $api = null;

    private ?ResendInbound $inbound = null;

    /**
     * @param array<string, mixed>                  $config this plugin's own config block
     * @param (\Closure(string $secret): bool)|null  $saveSecret writes the signing
     *        secret back into this plugin's config after setup
     * @param (\Closure(string $key, string $fallback): string)|null $translate a
     *        language lookup; null means the English written here is used as it
     *        stands, which is what a test wants
     * @param Http|null $http the outbound client; null is cURL
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?\Closure $saveSecret = null,
        private readonly ?\Closure $translate = null,
        private readonly ?Http $http = null,
    ) {
    }

    /** @return list<string> */
    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'Resend';
    }

    /**
     * What this transport does to a message on the way out.
     *
     * **Custom headers reach the wire.** Over SMTP because there the headers
     * are the first half of the message; over the API because Symfony's Resend
     * bridge copies every header it was handed into the request's `headers`
     * object, bypassing only the six Resend has its own fields for — from, to,
     * cc, bcc, subject and reply_to. `List-Unsubscribe` and
     * `List-Unsubscribe-Post` are not among those and survive both ways, which
     * is what puts the unsubscribe button next to the sender name in Gmail.
     *
     * **Nothing comes back, though.** Resend's webhooks carry no headers at
     * all, on any event, and their documentation says so outright: "Webhooks do
     * not include the email body, headers, or attachments, only their
     * metadata." So a send header is invisible at the other end and there is no
     * setting that changes it. What does come back is `data.tags`, and
     * `data.message_id`, which is the sender's own `Message-ID` — and that is
     * the path to rely on, because it needs nothing configured.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: false,
            echoNote: strtr($this->say(
                self::ECHO_NOTE_KEY,
                'Resend sends no message headers back in its webhooks — their words are that a webhook carries '
                . 'the metadata and not the body, the headers or the attachments — so %header% cannot be read at '
                . 'the far end and no setting changes that. Resend does echo the message\'s tags, so a store that '
                . 'sets a tag named %tag% gets it back on every event; Resend only allows letters, digits, '
                . 'underscores and hyphens in a tag name, which is why it is not spelled like the header. '
                . 'Matching on Message-ID needs none of this and works on every event, because Resend echoes it '
                . 'in full.'
            ), ['%header%' => SendHeader::name(), '%tag%' => ResendReports::tag()]),
        );
    }

    public function reports(): ?DeliveryReports
    {
        return $this->reports ??= new ResendReports();
    }

    public function setup(): ?WebhookSetup
    {
        return $this->setup ??= new ResendSetup($this->api(), $this->config, $this->saveSecret);
    }

    /**
     * What Resend needs a sending domain's DNS to say, and the way to ask their
     * API what it already says.
     *
     * All three of these are worth reading twice, because Resend is the one
     * provider here whose DNS is mostly somebody else's.
     *
     * - **SPF lives on the sending subdomain**, not the domain's own record:
     *   `send.example.com` (unless the account chose another) is a CNAME to
     *   `send.forge.rmta.net`, whose own SPF lists Resend's servers.
     * - **DKIM is a TXT record**, at `resend._domainkey`, holding the key
     *   itself. Not a CNAME, so there is no zone to check a selector against,
     *   which is why {@see DKIM_ZONE} is null.
     * - **The return path is that same sending subdomain**, which is why SPF
     *   aligns there. Older domains, from when Resend sent through Amazon SES,
     *   have an MX record to `feedback-smtp.<region>.amazonses.com` instead.
     *
     * The lookup asks the account rather than guessing. `resend` is the
     * selector on every Resend domain today and it is still read rather than
     * assumed, because a selector that changed and a check that carried on
     * asserting the old one is the kind of failure nobody notices for a year.
     */
    public function domain(): DomainFacts
    {
        $key = trim((string)($this->config['api_key'] ?? ''));

        return new DomainFacts(
            spfInclude: self::SPF_INCLUDE,
            dkimZone: self::DKIM_ZONE,
            returnPathZone: self::RETURN_PATH_ZONE,
            lookup: $key === ''
                ? null
                : fn (string $domain): array => $this->api()->domainFacts($key, $domain),
        );
    }

    /**
     * Doing it by hand, naming the screens and the boxes.
     *
     * "Configure a webhook" is not instructions. The signing secret is the part
     * everybody misses, because a webhook with no secret pasted back in looks
     * perfectly healthy in Resend's dashboard and has every one of its events
     * refused at the other end.
     */
    public function instructions(): string
    {
        return $this->say(
            self::INSTRUCTIONS_KEY,
            'In Resend, open Webhooks and press Add Webhook. Paste in this address and tick email.delivered, '
            . 'email.bounced, email.complained, email.opened, email.clicked, email.failed and '
            . 'email.suppressed. Once it is saved, open the '
            . 'webhook and copy its signing secret — it begins with whsec_ — into the Webhook signing secret '
            . 'field in the Email Resend plugin, because without it every event is refused. Or paste a '
            . 'full-access API key into that plugin and press Set up, which does all of it for you.'
        );
    }

    /**
     * Where a store sends its webhook test: {@see TEST_RECIPIENT}.
     *
     * Not part of the Email plugin's `Provider` interface, and that is the
     * point: a store asks `method_exists()` for it, so this class still loads
     * beside an Email plugin from before the method existed, and a store that
     * does not know about it never calls it. A constant, with no I/O, because
     * a settings screen reads it.
     */
    public function testRecipient(): ?string
    {
        return self::TEST_RECIPIENT;
    }

    /**
     * The receiver for mail sent to a Resend receiving domain.
     *
     * Answers the Email plugin's `InboundCapable::inbound()` on
     * {@see ResendInboundProvider}. Cheap and without I/O, like everything else
     * a settings screen calls. Only call it where the Email plugin has inbound
     * mail: {@see ResendInbound} implements that plugin's `InboundReceiver`.
     */
    public function inbound(): ResendInbound
    {
        return $this->inbound ??= new ResendInbound($this->config, $this->http);
    }

    /**
     * This plugin's own config block, for a caller that holds the provider and
     * needs the values {@see DeliveryReports::verificationKeys()} named.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    // ------------------------------------------------------------- internals

    private function api(): ResendApi
    {
        return $this->api ??= new ResendApi($this->http ?? new CurlHttp());
    }

    /**
     * A translated string, or the English one.
     *
     * The lookup itself belongs to the plugin file, which is the half that
     * knows about Grav; this class is handed a closure or nothing at all.
     * Everything is wrapped because this is called while a settings screen is
     * being drawn and a provider is not allowed to throw on one of those.
     */
    private function say(string $key, string $english): string
    {
        if ($this->translate === null) {
            return $english;
        }

        try {
            $said = ($this->translate)($key, $english);
        } catch (\Throwable) {
            return $english;
        }

        return !\is_string($said) || trim($said) === '' || $said === $key ? $english : $said;
    }
}
