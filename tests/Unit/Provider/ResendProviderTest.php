<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailResend\Provider\Http;
use Grav\Plugin\EmailResend\Provider\ResendApi;
use Grav\Plugin\EmailResend\Provider\ResendProvider;
use Grav\Plugin\EmailResend\Provider\ResendReports;
use PHPUnit\Framework\TestCase;

/**
 * The provider itself: the answers a settings screen asks for, and the promise
 * that asking for them costs nothing.
 */
final class ResendProviderTest extends TestCase
{
    public function testItAnswersForTheResendEngineUnderTheResendKey(): void
    {
        $provider = new ResendProvider();

        self::assertInstanceOf(Provider::class, $provider);
        self::assertSame(['resend'], $provider->engines());
        self::assertSame('resend', $provider->key());
        self::assertSame('Resend', $provider->label());
        self::assertInstanceOf(ResendReports::class, $provider->reports());
        self::assertInstanceOf(DeliveryReports::class, $provider->reports());
        self::assertInstanceOf(WebhookSetup::class, $provider->setup());
    }

    /** The registry it is registered into finds it both ways round. */
    public function testTheRegistryFindsItByEngineAndByKey(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new ResendProvider());

        self::assertInstanceOf(ResendProvider::class, $registry->forEngine('resend'));
        self::assertInstanceOf(ResendProvider::class, $registry->byKey('resend'));
        self::assertNull($registry->forEngine('ses'));
    }

    /**
     * Nothing on the cheap path touches the network.
     *
     * These are called every time a settings screen is drawn, and a round trip
     * in one of them is a settings screen that hangs when somebody else's API
     * is slow. The client here throws on any call, so a single request fails
     * the test rather than passing quietly.
     */
    public function testDrawingASettingsScreenMakesNoRequests(): void
    {
        $provider = new ResendProvider(['api_key' => 're_a_key'], null, null, self::hostile());

        $provider->engines();
        $provider->key();
        $provider->label();
        $provider->capabilities();
        $provider->domain();
        $provider->instructions();
        $provider->reports();
        $provider->setup();

        self::assertTrue(true, 'nothing above made a request');
    }

    /**
     * What Resend does to a message on the way out, and what it does not do on
     * the way back.
     */
    public function testCapabilitiesSayHeadersGoOutAndDoNotComeBack(): void
    {
        $capabilities = (new ResendProvider())->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders, 'Resend echoes no headers on any webhook');

        // The note is the whole value of a false here: it has to say what to do
        // instead, in plain words, on the same screen.
        self::assertStringContainsString(SendHeader::name(), $capabilities->echoNote);
        self::assertStringContainsString(ResendReports::tag(), $capabilities->echoNote);
        self::assertStringContainsString('Message-ID', $capabilities->echoNote);
    }

    /**
     * Resend's DNS is mostly Amazon's, because Resend sends through SES.
     *
     * The null DKIM zone is the one worth pinning: Resend publishes the key as
     * a TXT record rather than a CNAME, so there is no zone for a selector to
     * point into and a caller that invented one would be checking a name that
     * never existed.
     */
    public function testTheDomainFactsAreAmazonsWithADkimTxtRecord(): void
    {
        $facts = (new ResendProvider())->domain();

        self::assertSame('amazonses.com', $facts->spfInclude);
        self::assertNull($facts->dkimZone);
        self::assertSame('amazonses.com', $facts->returnPathZone);

        // No key configured means nothing to ask with, so there is no lookup
        // rather than one that fails.
        self::assertNull($facts->lookup);
        self::assertSame([], $facts->ask('example.com'));
    }

    /**
     * With a key, the lookup asks the account what its own DNS should say.
     *
     * Two calls: the list to find the domain's id, then the domain itself for
     * its records. The selector is read rather than assumed even though every
     * Resend domain uses `resend` today, because a selector that changed and a
     * check that carried on asserting the old one is the kind of failure nobody
     * notices for a year.
     */
    public function testTheLookupReadsTheSelectorAndReturnPathFromTheAccount(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['object' => 'list', 'data' => [
                ['id' => 'other', 'name' => 'somewhere-else.test'],
                ['id' => 'd91cd9bd', 'name' => 'example.com'],
            ]]),
            FakeHttp::answer(200, self::domainAnswer()),
        ]);

        $facts = (new ResendProvider(['api_key' => 're_a_key'], null, null, $http))->domain();

        self::assertSame(
            ['selectors' => ['resend'], 'return_paths' => ['send.example.com']],
            $facts->ask('example.com')
        );

        self::assertSame(ResendApi::BASE . '/domains', $http->call(0)['url']);
        self::assertSame(ResendApi::BASE . '/domains/d91cd9bd', $http->call(1)['url']);
        self::assertSame('Bearer re_a_key', $http->call(1)['headers']['Authorization']);
    }

    /** A store sending from a subdomain of the domain on the account is the same domain. */
    public function testASendingSubdomainFindsItsParentOnTheAccount(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['data' => [['id' => 'd91cd9bd', 'name' => 'example.com']]]),
            FakeHttp::answer(200, self::domainAnswer()),
        ]);

        $facts = (new ResendProvider(['api_key' => 're_a_key'], null, null, $http))->domain();

        self::assertSame(['resend'], $facts->ask('mail.example.com')['selectors']);
    }

    /**
     * An API that is slow, down or refusing the key is an unanswered question
     * rather than a broken screen.
     */
    public function testTheLookupAnswersEmptyRatherThanFailing(): void
    {
        foreach ([
            'a refused key' => [FakeHttp::answer(401, ['message' => 'API key is invalid'])],
            'no route out' => [FakeHttp::answer(0, null, 'Could not resolve host')],
            'a domain the account never had' => [FakeHttp::answer(200, ['data' => []])],
        ] as $case => $answers) {
            $facts = (new ResendProvider(['api_key' => 're_a_key'], null, null, new FakeHttp($answers)))->domain();

            self::assertSame([], $facts->ask('example.com')['selectors'], $case);
            self::assertSame([], $facts->ask('example.com')['return_paths'], $case);
        }
    }

    /** An HTTP client that throws anyway still costs an empty answer rather than a stack trace. */
    public function testALookupNeverThrows(): void
    {
        $facts = (new ResendProvider(['api_key' => 're_a_key'], null, null, self::hostile()))->domain();

        self::assertSame(['selectors' => [], 'return_paths' => []], $facts->ask('example.com'));
    }

    /** The answer is remembered, so a screen drawing two checks asks once. */
    public function testTheLookupAsksOncePerDomain(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['data' => [['id' => 'd91cd9bd', 'name' => 'example.com']]]),
            FakeHttp::answer(200, self::domainAnswer()),
        ]);

        $facts = (new ResendProvider(['api_key' => 're_a_key'], null, null, $http))->domain();

        $facts->ask('example.com');
        $facts->ask('example.com');

        self::assertCount(2, $http->calls, 'the second ask should have been answered from memory');
    }

    /** Instructions name the screens and the boxes, including the one everybody misses. */
    public function testTheInstructionsNameTheScreensAndTheSecret(): void
    {
        $instructions = (new ResendProvider())->instructions();

        self::assertStringContainsString('Webhooks', $instructions);
        self::assertStringContainsString('email.bounced', $instructions);
        self::assertStringContainsString('whsec_', $instructions);
    }

    /** A translation wins where there is one, and the English stands where there is not. */
    public function testATranslationIsUsedWhereThereIsOne(): void
    {
        $translated = new ResendProvider([], null, static fn (string $k, string $f): string => 'Auf Deutsch, bitte.');
        self::assertSame('Auf Deutsch, bitte.', $translated->instructions());

        // Grav answers with the key itself when nothing has been written for
        // the site's language, and so does a closure that throws.
        $untranslated = new ResendProvider([], null, static fn (string $k, string $f): string => $k);
        self::assertStringContainsString('Webhooks', $untranslated->instructions());

        $broken = new ResendProvider([], null, static function (string $k, string $f): string {
            throw new \RuntimeException('no language service on this site');
        });
        self::assertStringContainsString('Webhooks', $broken->instructions());
    }

    /** The plugin's own config block, for a caller that has to read a verification key out of it. */
    public function testItHandsBackItsOwnConfig(): void
    {
        $config = ['api_key' => 're_a_key', 'signing_secret' => 'whsec_x'];

        self::assertSame($config, (new ResendProvider($config))->config());
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> Resend's own documented answer for one domain */
    private static function domainAnswer(): array
    {
        return [
            'object' => 'domain',
            'id' => 'd91cd9bd',
            'name' => 'example.com',
            'status' => 'verified',
            'region' => 'us-east-1',
            'records' => [
                [
                    'record' => 'SPF',
                    'name' => 'send',
                    'type' => 'MX',
                    'value' => 'feedback-smtp.us-east-1.amazonses.com',
                    'priority' => 10,
                ],
                [
                    'record' => 'SPF',
                    'name' => 'send',
                    'type' => 'TXT',
                    'value' => '"v=spf1 include:amazonses.com ~all"',
                ],
                [
                    'record' => 'DKIM',
                    'name' => 'resend._domainkey',
                    'type' => 'TXT',
                    'value' => 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDsc4Lh8xilsngyKEgN2S84',
                ],
                [
                    'record' => 'Tracking',
                    'name' => 'links.example.com',
                    'type' => 'CNAME',
                    'value' => 'links1.resend-dns.com',
                ],
            ],
        ];
    }

    /** An {@see Http} that fails the test if anything calls it. */
    private static function hostile(): Http
    {
        return new class () implements Http {
            public function json(string $method, string $url, ?array $body = null, array $headers = []): array
            {
                throw new \RuntimeException(sprintf('nothing here should have called %s %s', $method, $url));
            }
        };
    }
}
