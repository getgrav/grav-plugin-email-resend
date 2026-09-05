<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\EmailResend\Provider\Http;

/**
 * An {@see Http} that answers from a script and writes down what it was asked.
 *
 * Most of what can go wrong with the setup flow is a call being made with the
 * wrong body or in the wrong order rather than a call failing — a webhook
 * registered for the wrong events looks perfectly healthy in Resend's dashboard
 * and reports nothing. So this records every request as well as answering it,
 * and the tests assert on both halves.
 *
 * An answer is popped per call, so a test says what happens on the first call
 * and the second by writing them down in that order. Running out of answers is
 * a failure with a message rather than a null, because "the code made a call
 * nobody expected" is exactly the sort of thing worth hearing about.
 */
final class FakeHttp implements Http
{
    /** @var list<array{method: string, url: string, body: array<array-key, mixed>|null, headers: array<string, string>}> */
    public array $calls = [];

    /** @param list<array{status: int, body: array<array-key, mixed>|null, error: string}> $answers */
    public function __construct(private array $answers = [])
    {
    }

    /**
     * A client that answers the setup flow the way a happy Resend does: no
     * webhook at this address yet, then one created with a secret.
     */
    public static function happy(
        string $id = '4dd369bc-aa82-4ff3-97de-514ae3000ee0',
        string $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
    ): self {
        return new self([
            self::answer(200, ['object' => 'list', 'has_more' => false, 'data' => []]),
            self::answer(200, ['object' => 'webhook', 'id' => $id, 'signing_secret' => $secret]),
        ]);
    }

    /**
     * @param  array<array-key, mixed>|null $body
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    public static function answer(int $status, ?array $body = null, string $error = ''): array
    {
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }

    public function json(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->answers === []) {
            throw new \RuntimeException(sprintf(
                'The code made a call nobody scripted an answer for: %s %s',
                strtoupper($method),
                $url
            ));
        }

        return array_shift($this->answers);
    }

    /** @return array{method: string, url: string, body: array<array-key, mixed>|null, headers: array<string, string>} */
    public function call(int $index): array
    {
        return $this->calls[$index];
    }
}
