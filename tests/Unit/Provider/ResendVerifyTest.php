<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailResend\Provider\ResendReports;
use PHPUnit\Framework\TestCase;

/**
 * Resend signs every webhook through Svix, and this is the test that a forged
 * one is refused.
 *
 * Every signature here except the published vector is computed for real against
 * a key this test minted, rather than pasted from a sample. A test built on one
 * fixed signature can only check that one string equals another; a test that
 * signs and then verifies checks that the thing being signed is the thing Svix
 * signs, which is where every one of these goes wrong.
 *
 * The forged cases are the point. A verifier that accepted everything would
 * pass a test that only fed it genuine payloads, and a store whose signing
 * secret was wrong would then be acting on anything anybody posted at a URL
 * they had guessed.
 */
final class ResendVerifyTest extends TestCase
{
    /**
     * Svix's own published test vector.
     *
     * Their documentation gives a secret, a payload, an id and a timestamp with
     * the signature they produce. If this fails, the signing string or the key
     * derivation is wrong, whatever a hand-rolled test of our own would say.
     */
    public function testItVerifiesSvixOwnTestVector(): void
    {
        $body = '{"event_type":"ping","data":{"success":true}}';
        $timestamp = 1731705121;

        $reports = new ResendReports(static fn (): int => $timestamp);

        $verdict = $reports->verify(self::request($body, [
            'svix-id' => 'msg_loFOjxBNrRLzqYUf',
            'svix-timestamp' => (string)$timestamp,
            'svix-signature' => 'v1,rAvfW3dJ/X/qxhsaXPOyyCGmRKsaKWcsNccKXlIktD0=',
        ]), ['signing_secret' => 'whsec_plJ3nmyCDGBKInavdOK15jsl']);

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed);
    }

    /** A genuine signature over a genuine body, which is the baseline for everything below. */
    public function testAGenuineSignatureIsAccepted(): void
    {
        $body = '{"type":"email.delivered"}';
        $secret = self::secret();
        $now = 1737000000;

        $verdict = (new ResendReports(static fn (): int => $now))
            ->verify(self::request($body, self::svix($body, $secret, $now)), ['signing_secret' => $secret]);

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed, 'Resend signs, so this is never an unsigned pass');
    }

    /** One byte changed in the body, everything else identical. */
    public function testATamperedBodyIsRefused(): void
    {
        $secret = self::secret();
        $now = 1737000000;
        $headers = self::svix('{"type":"email.bounced"}', $secret, $now);

        $verdict = (new ResendReports(static fn (): int => $now))
            ->verify(self::request('{"type":"email.opened"}', $headers), ['signing_secret' => $secret]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);
    }

    /** The signature itself replaced with one somebody made up. */
    public function testAForgedSignatureIsRefused(): void
    {
        $body = '{"type":"email.bounced"}';
        $secret = self::secret();
        $now = 1737000000;

        $headers = self::svix($body, $secret, $now);
        $headers['svix-signature'] = 'v1,' . base64_encode(str_repeat('x', 32));

        self::assertFalse(
            (new ResendReports(static fn (): int => $now))
                ->verify(self::request($body, $headers), ['signing_secret' => $secret])->ok
        );
    }

    /**
     * A body signed with a different secret, which is the mistake a merchant
     * actually makes — the webhook was recreated and the old secret is still in
     * the field.
     */
    public function testTheWrongSecretIsRefused(): void
    {
        $body = '{"type":"email.delivered"}';
        $now = 1737000000;

        $headers = self::svix($body, self::secret('one'), $now);

        self::assertFalse(
            (new ResendReports(static fn (): int => $now))
                ->verify(self::request($body, $headers), ['signing_secret' => self::secret('two')])->ok
        );
    }

    /**
     * No secret configured is a refusal, not a free pass.
     *
     * Without this, a store that had never pasted the signing secret in would
     * be acting on anything posted at an address somebody guessed.
     */
    public function testAMissingSecretIsARefusalRatherThanAFreePass(): void
    {
        $body = '{"type":"email.delivered"}';
        $now = 1737000000;
        $headers = self::svix($body, self::secret(), $now);

        $reports = new ResendReports(static fn (): int => $now);

        foreach ([[], ['signing_secret' => ''], ['signing_secret' => '   ']] as $config) {
            $verdict = $reports->verify(self::request($body, $headers), $config);

            self::assertFalse($verdict->ok, var_export($config, true));
            self::assertStringContainsString('no Resend signing secret', $verdict->reason);
        }
    }

    /** A secret that is not base64 at all cannot key an HMAC, and says so. */
    public function testASecretThatIsNotAWhsecValueIsRefused(): void
    {
        $body = '{"type":"email.delivered"}';
        $now = 1737000000;

        $verdict = (new ResendReports(static fn (): int => $now))->verify(
            self::request($body, self::svix($body, self::secret(), $now)),
            ['signing_secret' => 'whsec_not base64 at all !!']
        );

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('whsec_', $verdict->reason);
    }

    /**
     * A signature captured once must not work forever.
     *
     * Svix's own libraries use five minutes and so does this.
     */
    public function testAStaleTimestampIsRefused(): void
    {
        $body = '{"type":"email.bounced"}';
        $secret = self::secret();
        $signedAt = 1737000000;

        $headers = self::svix($body, $secret, $signedAt);

        $fresh = new ResendReports(static fn (): int => $signedAt + 60);
        self::assertTrue($fresh->verify(self::request($body, $headers), ['signing_secret' => $secret])->ok);

        $stale = new ResendReports(static fn (): int => $signedAt + 3600);
        $verdict = $stale->verify(self::request($body, $headers), ['signing_secret' => $secret]);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('tolerance', $verdict->reason);

        // And a clock the other way round, which is a replay dated in the
        // future rather than one dated in the past.
        $early = new ResendReports(static fn (): int => $signedAt - 3600);
        self::assertFalse($early->verify(self::request($body, $headers), ['signing_secret' => $secret])->ok);
    }

    /** Headers that never arrived are refused before any HMAC is computed. */
    public function testMissingSignatureHeadersAreRefused(): void
    {
        $body = '{"type":"email.delivered"}';
        $secret = self::secret();
        $now = 1737000000;

        $reports = new ResendReports(static fn (): int => $now);
        $full = self::svix($body, $secret, $now);

        foreach (['svix-id', 'svix-timestamp', 'svix-signature'] as $missing) {
            $headers = $full;
            unset($headers[$missing]);

            $verdict = $reports->verify(self::request($body, $headers), ['signing_secret' => $secret]);

            self::assertFalse($verdict->ok, "{$missing} should not be optional");
            self::assertStringContainsString('missing', $verdict->reason);
        }
    }

    /** Svix white-labels the header names for its larger customers. */
    public function testTheWhiteLabelledHeaderNamesAreAccepted(): void
    {
        $body = '{"type":"email.delivered"}';
        $secret = self::secret();
        $now = 1737000000;

        $svix = self::svix($body, $secret, $now);

        $verdict = (new ResendReports(static fn (): int => $now))->verify(self::request($body, [
            'webhook-id' => $svix['svix-id'],
            'webhook-timestamp' => $svix['svix-timestamp'],
            'webhook-signature' => $svix['svix-signature'],
        ]), ['signing_secret' => $secret]);

        self::assertTrue($verdict->ok, $verdict->reason);
    }

    /**
     * The header is a list, so a secret can be rotated without a gap.
     *
     * Svix sends every version it currently holds, space separated, and one of
     * them matching is a pass.
     */
    public function testOneMatchInARotationListIsEnough(): void
    {
        $body = '{"type":"email.delivered"}';
        $secret = self::secret();
        $now = 1737000000;

        $svix = self::svix($body, $secret, $now);
        $svix['svix-signature'] = 'v1,' . base64_encode(str_repeat('x', 32)) . ' ' . $svix['svix-signature'];

        self::assertTrue(
            (new ResendReports(static fn (): int => $now))
                ->verify(self::request($body, $svix), ['signing_secret' => $secret])->ok
        );
    }

    /** A signature scheme this code has never seen is not silently accepted. */
    public function testAnUnknownSignatureVersionIsRefused(): void
    {
        $body = '{"type":"email.delivered"}';
        $secret = self::secret();
        $now = 1737000000;

        $svix = self::svix($body, $secret, $now);
        $svix['svix-signature'] = str_replace('v1,', 'v9,', $svix['svix-signature']);

        self::assertFalse(
            (new ResendReports(static fn (): int => $now))
                ->verify(self::request($body, $svix), ['signing_secret' => $secret])->ok
        );
    }

    /** The one credential this provider needs, named where a settings screen can find it. */
    public function testItNamesTheOneConfigKeyItVerifiesWith(): void
    {
        self::assertSame(['signing_secret'], (new ResendReports())->verificationKeys());
    }

    // ------------------------------------------------------------- internals

    private static function secret(string $salt = 'default'): string
    {
        return 'whsec_' . base64_encode(str_pad($salt, 24, '-signing-secret'));
    }

    /** @return array<string, string> */
    private static function svix(string $body, string $secret, int $now): array
    {
        $id = 'msg_' . substr(sha1($body), 0, 16);
        $key = base64_decode(substr($secret, 6), true);

        return [
            'svix-id' => $id,
            'svix-timestamp' => (string)$now,
            'svix-signature' => 'v1,' . base64_encode(
                hash_hmac('sha256', $id . '.' . $now . '.' . $body, (string)$key, true)
            ),
        ];
    }

    /** @param array<string, string> $headers */
    private static function request(string $body, array $headers): WebhookRequest
    {
        return new WebhookRequest('POST', '/hook', [], $headers, $body);
    }
}
