<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Provider;

/**
 * The outbound calls this plugin makes to Resend's own API, behind one seam.
 *
 * Four of them: list the account's webhooks, create one, list the account's
 * sending domains and read one domain's DNS records back. The first two are
 * behind a button; the other two are behind the domain lookup on
 * {@see \Grav\Plugin\Email\Providers\DomainFacts}, which a caller decides when
 * to run and is expected to cache.
 *
 * The seam exists so the suite can answer for itself. A test that reached
 * api.resend.com would need a real API key, would fail on a train, and would be
 * testing Resend rather than this plugin.
 *
 * Deliberately tiny: one method, no redirect policy of its own, no streaming.
 * None of the four calls needs any of that, and every option is another thing
 * to get wrong in the one class that talks to the outside.
 */
interface Http
{
    /**
     * Make a request and read a JSON answer.
     *
     * Never throws. A refused connection, a certificate that did not check out
     * and a body that was not JSON all come back as a status and an error
     * string, because every caller here treats them the same way — it says what
     * happened, in plain words, and stops.
     *
     * @param  string                       $method  GET or POST
     * @param  array<array-key, mixed>|null $body    JSON request body, or null for none
     * @param  array<string, string>        $headers
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    public function json(string $method, string $url, ?array $body = null, array $headers = []): array;
}
