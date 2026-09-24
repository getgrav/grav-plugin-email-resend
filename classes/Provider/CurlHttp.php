<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * {@see Http} over cURL, with every switch that matters set explicitly.
 *
 * The settings are not defaults and they are not decoration:
 *
 * - **HTTPS only**, on the request and on its redirects. An API key travels in
 *   a header on every one of these calls, and a plain-HTTP hop would put it on
 *   the wire in clear.
 * - **Peer and host verification on.** Off in nobody's build, and worth stating
 *   anyway: this is the class where turning it off would be quiet and bad.
 * - **Two redirects.** Enough for an endpoint that moved, not enough to be
 *   walked around a network.
 * - **A response cap.** Resend's largest answer here is a list of an account's
 *   sending domains with their DNS records, which is a few kilobytes. Ten
 *   megabytes of anything is somebody pointing this at a file server, and the
 *   write callback stops reading rather than filling memory.
 * - **Short timeouts.** Two of these calls run behind a button a merchant is
 *   watching and two run while a settings screen is being drawn. A slow API has
 *   to become a sentence on a screen quickly, rather than a request that
 *   eventually hits the PHP time limit.
 */
final class CurlHttp implements Http, Download
{
    /** Seconds to connect. */
    public const CONNECT_TIMEOUT = 5;

    /** Seconds for the whole call. */
    public const TIMEOUT = 15;

    /** Bytes of response body kept before the transfer is abandoned. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function json(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $method = strtoupper(trim($method));

        $payload = null;
        if ($body !== null) {
            $encoded = json_encode($body, \JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return ['status' => 0, 'body' => null, 'error' => 'the request body could not be encoded'];
            }
            $payload = $encoded;
            $headers += ['Content-Type' => 'application/json'];
        }

        $answer = $this->run($method, $url, $payload, $headers + ['Accept' => 'application/json']);

        $decoded = null;
        if ($answer['raw'] !== null && trim($answer['raw']) !== '') {
            try {
                $parsed = json_decode($answer['raw'], true, 32, \JSON_THROW_ON_ERROR);
                $decoded = \is_array($parsed) ? $parsed : null;
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return ['status' => $answer['status'], 'body' => $decoded, 'error' => $answer['error']];
    }

    /**
     * A plain GET of a signed download address, bytes back as they came.
     *
     * The same switches as {@see json()}, with the cap and the timeout the
     * caller names, because this is the one call here that moves a whole
     * received message (Resend accepts up to 40 MB) and it runs in a job worker
     * rather than behind a screen. No headers are sent: the address carries its
     * own signature, and the API key has no business travelling to a CDN.
     */
    public function download(string $url, int $maxBytes, int $timeout): array
    {
        $answer = $this->run('GET', $url, null, [], max(1, $maxBytes), max(1, $timeout));

        return ['status' => $answer['status'], 'body' => $answer['raw'], 'error' => $answer['error']];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, raw: string|null, error: string}
     */
    private function run(
        string $method,
        string $url,
        ?string $payload,
        array $headers,
        int $maxBytes = self::MAX_BYTES,
        int $timeout = self::TIMEOUT,
    ): array {
        if (!\function_exists('curl_init')) {
            return ['status' => 0, 'raw' => null, 'error' => 'this installation has no cURL'];
        }

        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            return ['status' => 0, 'raw' => null, 'error' => 'only https addresses are fetched'];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'raw' => null, 'error' => 'the request could not be started'];
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $collected = '';

        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => $timeout,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 2,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_WRITEFUNCTION => static function ($_, string $chunk) use (&$collected, $maxBytes): int {
                $collected .= $chunk;

                // Returning fewer bytes than were handed over is how cURL is
                // told to stop, which is the point: a body over the cap is
                // abandoned rather than assembled and then thrown away.
                return \strlen($collected) > $maxBytes ? 0 : \strlen($chunk);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $payload);
        }

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string)curl_error($handle) : '';
        if (\strlen($collected) > $maxBytes) {
            $error = sprintf('the answer was larger than %d bytes, so it was abandoned', $maxBytes);
            $collected = '';
        }
        curl_close($handle);

        return [
            'status' => $status,
            'raw' => $error === '' || $collected !== '' ? $collected : null,
            'error' => $error,
        ];
    }
}
