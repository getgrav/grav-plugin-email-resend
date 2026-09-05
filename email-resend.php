<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendSmtpTransport;

/**
 * Resend for Grav's Email plugin.
 *
 * The same job every `email-<provider>` plugin does: register an engine name on
 * the Email plugin's Mail Engine list, and hand it something that can send when
 * that engine is the one chosen.
 *
 * ## The PHP floor
 *
 * 8.1, and it is not a preference: Symfony's Resend bridge requires it. There is
 * no version of this plugin that could work on the 7.4 sites Grav 1.7 still
 * supports, so the whole plugin says so rather than guarding half of itself.
 */
class EmailResendPlugin extends Plugin
{
    /** The engine name this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'resend';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines' => ['onEmailEngines', 0],
            'onEmailTransportDsn' => ['onEmailTransportDsn', 0],
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
}
