<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A Symfony HTTP client that writes the request down and answers a fixed
 * success, so that {@see \Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport}
 * can be sent through without anything leaving the machine.
 *
 * The bridge builds its payload in a private method, so the only honest way to
 * read what it built is to look at the request it made.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $options = [];

    public string $url = '';

    /** @param array<string, mixed> $answer */
    public function __construct(private readonly array $answer = ['id' => 'an-id'])
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->url = $url;
        $this->options = $options;

        return new FixedResponse($this->answer);
    }

    /** @return array<string, mixed> the JSON body the bridge built */
    public function lastJson(): array
    {
        return \is_array($this->options['json'] ?? null) ? $this->options['json'] : [];
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
