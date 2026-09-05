<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A 200 with a body written down in advance, which is everything the Resend
 * bridge asks of a response.
 */
final class FixedResponse implements ResponseInterface
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly array $body)
    {
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['application/json']];
    }

    public function getContent(bool $throw = true): string
    {
        return (string)json_encode($this->body);
    }

    /** @return array<array-key, mixed> */
    public function toArray(bool $throw = true): array
    {
        return $this->body;
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = ['http_code' => 200, 'url' => 'https://api.resend.com/emails'];

        return $type === null ? $info : ($info[$type] ?? null);
    }
}
