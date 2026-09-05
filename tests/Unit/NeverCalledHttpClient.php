<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A client for the tests that only build a transport and never send through it.
 *
 * Handed over rather than left null so that building one does not go looking
 * for Symfony's real HTTP client, which is Grav's to provide at runtime and is
 * not this plugin's to require.
 */
final class NeverCalledHttpClient implements HttpClientInterface
{
    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        throw new \BadMethodCallException(sprintf('nothing here should have called %s %s', $method, $url));
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \BadMethodCallException('nothing here streams');
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
