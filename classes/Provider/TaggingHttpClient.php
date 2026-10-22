<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

use Grav\Plugin\Email\Providers\SendHeader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The HTTP client under Symfony's Resend API transport, adding two tags to
 * every message on its way out.
 *
 * - `grav-site`, so a site can turn away the events of other sites sharing the
 *   same Resend team ({@see SiteTag}).
 * - `grav-send-id`, the newsletter's send id copied out of the send header,
 *   because Resend echoes tags on its events and no headers at all.
 *
 * The bridge's transport is final and builds its body in a private method, so
 * the tags go in here, on the JSON it hands over, rather than in a copy of it.
 * The bridge's own tag support is no use: Symfony 7.3 writes each tag as
 * `{"X-Tag": value}`, which is not the `{name, value}` Resend takes.
 */
final class TaggingHttpClient implements HttpClientInterface
{
    /** Resend's limit on a tag value, and its alphabet. */
    private const VALUE = '/^[A-Za-z0-9_\-]{1,256}$/';

    private HttpClientInterface $inner;

    public function __construct(private readonly string $site, ?HttpClientInterface $inner = null)
    {
        $this->inner = $inner ?? HttpClient::create();
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($method === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/emails') && \is_array($options['json'] ?? null)) {
            $options['json'] = self::tagged($options['json'], $this->site);
        }

        return $this->inner->request($method, $url, $options);
    }

    /**
     * The body with this site's tags added beside any already there.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function tagged(array $json, string $site): array
    {
        $tags = array_values(array_filter(
            \is_array($json['tags'] ?? null) ? $json['tags'] : [],
            static fn (mixed $tag): bool => \is_array($tag) && isset($tag['name'], $tag['value'])
                && $tag['name'] !== SiteTag::NAME
        ));

        if (preg_match(self::VALUE, $site) === 1) {
            $tags[] = ['name' => SiteTag::NAME, 'value' => $site];
        }

        // The send header belongs to Email 5.1 and later; an older one has no
        // send ids to copy.
        if (class_exists(SendHeader::class)) {
            $sendId = SendHeader::idIn(\is_array($json['headers'] ?? null) ? $json['headers'] : null);
            if ($sendId !== null && preg_match(self::VALUE, $sendId) === 1) {
                $tags[] = ['name' => ResendReports::tag(), 'value' => $sendId];
            }
        }

        if ($tags === []) {
            unset($json['tags']);
        } else {
            $json['tags'] = $tags;
        }

        return $json;
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }
}
