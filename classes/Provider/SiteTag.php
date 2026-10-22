<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * The tag that says which site sent a message.
 *
 * Resend webhooks belong to the whole Resend team, not to a domain or an API
 * key, so every site sending through one team gets every other site's events.
 * Without a way to tell them apart, one site's test reads as another site's
 * answer, and a bounce from one site's list suppresses the address on another.
 *
 * So every message sent over the API carries a `grav-site` tag, and Resend
 * echoes tags on every event. A site turns away an event tagged for a
 * different site and keeps one with no tag at all, which is mail sent before
 * this existed or over SMTP.
 *
 * The value is a hash of the site's folder, worked out the same way when
 * sending and when receiving, so it needs no setting and survives a new API
 * key. Only the folder: `GRAV_ROOT` is the same real path in a web request and
 * on the command line, where a campaign is sent from, and anything else
 * (the machine's name, say) can be switched off for one and not the other,
 * which would have a site turning away its own events. Two sites at the same
 * path on two machines get the same value and keep each other's events, which
 * is how things were before.
 */
final class SiteTag
{
    /** The tag's name. Resend allows letters, digits, underscores and hyphens. */
    public const NAME = 'grav-site';

    /** This site's value. */
    public static function value(?string $root = null): string
    {
        $root ??= \defined('GRAV_ROOT') ? (string)GRAV_ROOT : (string)getcwd();

        return 's' . substr(hash('sha256', rtrim($root, '/')), 0, 20);
    }

    /**
     * The `grav-site` value on an event, or null where it has none.
     *
     * Resend documents `data.tags` as a map; a `{name, value}` list, the form
     * the sending API takes, is read too.
     */
    public static function in(mixed $tags): ?string
    {
        if (!\is_array($tags)) {
            return null;
        }

        $value = $tags[self::NAME] ?? null;

        if ($value === null && array_is_list($tags)) {
            foreach ($tags as $tag) {
                if (\is_array($tag) && ($tag['name'] ?? null) === self::NAME) {
                    $value = $tag['value'] ?? null;
                    break;
                }
            }
        }

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
