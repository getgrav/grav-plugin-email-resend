<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\EmailResend\Provider\Download;

/**
 * A {@see Download} that answers from a map of address to bytes and writes down
 * every address it was asked for, so a test can check that a signed link was
 * fetched and nothing else was.
 */
final class FakeDownload implements Download
{
    /** @var list<array{url: string, maxBytes: int, timeout: int}> */
    public array $calls = [];

    /** @param array<string, array{status: int, body: string|null, error: string}> $answers */
    public function __construct(private array $answers = [])
    {
    }

    /** @return array{status: int, body: string|null, error: string} */
    public static function ok(string $bytes): array
    {
        return ['status' => 200, 'body' => $bytes, 'error' => ''];
    }

    public function download(string $url, int $maxBytes, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'maxBytes' => $maxBytes, 'timeout' => $timeout];

        if (!isset($this->answers[$url])) {
            throw new \RuntimeException('The code downloaded an address nobody scripted: ' . $url);
        }

        return $this->answers[$url];
    }
}
