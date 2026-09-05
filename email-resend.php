<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\EmailResend\Provider\ResendProvider;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendSmtpTransport;

/**
 * Resend for Grav's Email plugin: sending, and everything Resend knows about
 * itself.
 *
 * Two halves. The first is the transport, which is the same job every
 * `email-<provider>` plugin does — register an engine name and hand the Email
 * plugin something that can send. The second is the provider, which is new: how
 * Resend's delivery webhooks are checked and read, how one is created from the
 * API key already pasted in here, and what a sending domain's DNS has to say.
 * Anything on the site that wants one of those answers asks the Email plugin,
 * which asks this.
 *
 * ## The PHP floor
 *
 * 8.1, and it is not a preference. Symfony's Resend bridge requires it, and so
 * does the Email plugin's provider contract, which uses readonly promoted
 * properties. There is no version of this plugin that could work on the 7.4
 * sites Grav 1.7 still supports, so the whole plugin says so rather than
 * guarding half of itself.
 */
class EmailResendPlugin extends Plugin
{
    /** The engine name this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'resend';

    /** The config file this plugin's settings are saved into. */
    public const CONFIG_FILE = 'config://plugins/email-resend.yaml';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines' => ['onEmailEngines', 0],
            'onEmailTransportDsn' => ['onEmailTransportDsn', 0],
            'onEmailProviders' => ['onEmailProviders', 0],
        ];
    }

    /**
     * Composer autoload.
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e): void
    {
        $engines = $e['engines'];
        $engines->resend = 'Resend';
    }

    /**
     * Hand the Email plugin a transport for the `resend` engine.
     *
     * Both are Symfony's own bridge classes. The API one is the faster of the
     * two and the one Resend push people towards; the SMTP one exists because
     * some hosts will not let a request out to an API and will let one out to
     * port 465. Resend's SMTP user is the literal word `resend` for everybody
     * and the password is the same API key, which is why there is no separate
     * username and password to fill in here — the bridge writes the username
     * itself.
     */
    public function onEmailTransportDsn(Event $e): void
    {
        if ($e['engine'] !== self::ENGINE) {
            return;
        }

        $options = (array)$this->config->get('plugins.email-resend', []);
        $key = (string)($options['api_key'] ?? '');

        $e['dsn'] = ($options['transport'] ?? 'api') === 'smtp'
            ? new ResendSmtpTransport($key)
            : new ResendApiTransport($key);

        $e->stopPropagation();
    }

    /**
     * Tell the Email plugin what Resend knows about itself.
     *
     * The event only exists on an Email plugin new enough to own the provider
     * contract. An older one never fires it and this plugin sends exactly as it
     * would have.
     */
    public function onEmailProviders(Event $e): void
    {
        $registry = $e['providers'] ?? null;
        if ($registry === null) {
            return;
        }

        $registry->add(new ResendProvider(
            (array)$this->config->get('plugins.email-resend', []),
            fn (string $secret): bool => $this->saveSetting('signing_secret', $secret),
            fn (string $key, string $fallback): string => $this->say($key, $fallback),
        ));
    }

    /**
     * Write one setting into this plugin's own config, and mean it for the rest
     * of the request too.
     *
     * Resend hands the signing secret over once, in the answer to the call that
     * creates the webhook, and never shows it again through the API. So it goes
     * straight in here rather than being printed for somebody to copy across.
     *
     * Answering false rather than throwing is deliberate: the caller's fallback
     * is to tell the merchant where to find the secret in Resend's own
     * dashboard, which is a worse afternoon than a writable config file and a
     * far better one than a webhook whose every event is refused.
     */
    protected function saveSetting(string $name, string $value): bool
    {
        try {
            $path = Grav::instance()['locator']->findResource(self::CONFIG_FILE, true, true);
            if (!$path) {
                return false;
            }

            $file = CompiledYamlFile::instance($path);
            $content = (array)$file->content();
            $content[$name] = $value;
            $file->save($content);
            $file->free();

            $this->config->set('plugins.email-resend.' . $name, $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One language string, falling back to the English written in the provider.
     *
     * Grav answers with the key itself when nothing has translated it, so that
     * is what "no translation" looks like here.
     */
    protected function say(string $key, string $fallback): string
    {
        try {
            $said = Grav::instance()['language']->translate([$key]);
        } catch (\Throwable) {
            return $fallback;
        }

        return !\is_string($said) || $said === '' || $said === $key ? $fallback : $said;
    }
}
