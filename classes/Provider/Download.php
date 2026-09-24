<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * A plain GET of a signed download address, for the bytes rather than for
 * JSON.
 *
 * Kept apart from {@see Http} so that adding it changed nothing for anything
 * already written against that interface. Only the inbound receiver uses it:
 * Resend hands over a received message as a signed link to the raw file, and
 * each attachment the same way, and none of those are JSON.
 */
interface Download
{
    /**
     * Fetch an address and answer its body as it came.
     *
     * Never throws. A refused connection, a certificate that did not check out
     * and an answer over the cap all come back as a status and an error string.
     *
     * @return array{status: int, body: string|null, error: string}
     */
    public function download(string $url, int $maxBytes, int $timeout): array;
}
