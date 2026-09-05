<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailResend\Provider\ResendApi;
use Grav\Plugin\EmailResend\Provider\ResendSetup;
use PHPUnit\Framework\TestCase;

/**
 * The one button, against a client that answers from a script.
 *
 * What is being pinned is mostly the request rather than the answer: a webhook
 * created for the wrong events, or at an address that already has one, or whose
 * signing secret was thrown away, all look like a success from the outside and
 * report nothing at all afterwards.
 */
final class ResendSetupTest extends TestCase
{
    private const URL = 'https://store.example.com/newsletter/webhook/resend/abcdefghijklmnop';

    /** The happy path: no webhook there yet, one created, its secret kept. */
    public function testItCreatesTheWebhookAndKeepsTheSigningSecret(): void
    {
        $http = FakeHttp::happy();
        $saved = [];

        $result = $this->button($http, function (string $secret) use (&$saved): bool {
            $saved[] = $secret;

            return true;
        })->create(self::URL, Event::TYPES, []);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('4dd369bc-aa82-4ff3-97de-514ae3000ee0', $result->webhookId);
        self::assertSame(['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'], $saved);
        self::assertStringContainsString('saved', $result->message);

        // The list first, so a second press does not leave two webhooks at one
        // address with two different signing secrets.
        self::assertSame('GET', $http->call(0)['method']);
        self::assertSame(ResendApi::BASE . '/webhooks', $http->call(0)['url']);

        $create = $http->call(1);
        self::assertSame('POST', $create['method']);
        self::assertSame(ResendApi::BASE . '/webhooks', $create['url']);
        self::assertSame(self::URL, $create['body']['endpoint']);
        self::assertSame('Bearer re_a_sending_key', $create['headers']['Authorization']);

        // The six contract words as Resend's seven event names: `dropped` is
        // both `email.failed` and `email.suppressed`, because Resend refuses to
        // send for a reason of its own and for an address already on its list,
        // and neither ever reaches a receiving server.
        self::assertSame([
            'email.delivered',
            'email.bounced',
            'email.complained',
            'email.opened',
            'email.clicked',
            'email.failed',
            'email.suppressed',
        ], $create['body']['events']);
    }

    /** Asking for nothing registers all of them, because a webhook for no events never fires. */
    public function testAskingForNoEventsRegistersAllOfThem(): void
    {
        $http = FakeHttp::happy();

        $this->button($http)->create(self::URL, [], []);

        self::assertSame(ResendApi::EVENTS, $http->call(1)['body']['events']);
    }

    /** Asking for a subset registers that subset and nothing else. */
    public function testASubsetOfEventsIsRegisteredAsAsked(): void
    {
        $http = FakeHttp::happy();

        $this->button($http)->create(self::URL, [Event::BOUNCED, Event::COMPLAINED], []);

        self::assertSame(['email.bounced', 'email.complained'], $http->call(1)['body']['events']);
    }

    /**
     * Pressing it twice leaves one webhook.
     *
     * Two at one address is worse here than for most providers: each gets its
     * own signing secret, so half the events would be signed with a secret the
     * store never saw and would be refused with nothing saying why. The message
     * says that, because a merchant pressing the button a second time is
     * usually a merchant whose events are being refused.
     */
    public function testASecondPressAddsNothing(): void
    {
        $http = new FakeHttp([FakeHttp::answer(200, ['data' => [
            ['id' => 'other', 'endpoint' => 'https://elsewhere.example.com/hook'],
            ['id' => 'ours', 'endpoint' => self::URL . '/'],
        ]])]);

        $result = $this->button($http)->create(self::URL, Event::TYPES, []);

        self::assertTrue($result->ok);
        self::assertSame('ours', $result->webhookId);
        self::assertCount(1, $http->calls, 'nothing should have been created');
        self::assertStringContainsString('already has a webhook', $result->message);
        self::assertStringContainsString('signing secret', $result->message);
    }

    /**
     * A key that is not allowed to manage webhooks, in Resend's own words plus
     * the box to tick.
     *
     * Their refusal is a sentence written for a person, and a sentence on its
     * own still leaves a merchant wondering what to do about it.
     */
    public function testARefusedKeyComesBackInResendsWordsWithThePermission(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(401, ['statusCode' => 401, 'name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails']),
            FakeHttp::answer(401, ['statusCode' => 401, 'name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails']),
        ]);

        $result = $this->button($http)->create(self::URL, Event::TYPES, []);

        self::assertFalse($result->ok);
        self::assertNull($result->webhookId);
        self::assertStringContainsString('This API key is restricted to only send emails', $result->message);
        self::assertStringContainsString(ResendSetup::PERMISSIONS, $result->message);
    }

    /** A refusal Resend did not explain is the status number and nothing invented. */
    public function testARefusalWithNoMessageSaysSoPlainly(): void
    {
        $http = new FakeHttp([FakeHttp::answer(500, null), FakeHttp::answer(500, null)]);

        $result = $this->button($http)->create(self::URL, Event::TYPES, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('500', $result->message);
    }

    /** Nothing got out at all, and the merchant is told which network problem it was. */
    public function testANetworkFailureSaysResendCouldNotBeReached(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(0, null, 'Could not resolve host: api.resend.com'),
            FakeHttp::answer(0, null, 'Could not resolve host: api.resend.com'),
        ]);

        $result = $this->button($http)->create(self::URL, Event::TYPES, []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /**
     * The webhook exists and its secret could not be kept, which is not half
     * set up: it is a webhook whose every event will be refused.
     */
    public function testASecretThatCouldNotBeSavedSendsTheMerchantToTheDashboard(): void
    {
        $refused = $this->button(FakeHttp::happy(), static fn (string $s): bool => false)
            ->create(self::URL, Event::TYPES, []);

        self::assertTrue($refused->ok);
        self::assertStringContainsString('could not be saved', $refused->message);
        self::assertStringContainsString('whsec_', $refused->message);
        self::assertStringNotContainsString('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $refused->message);

        // A closure that throws is the same answer, not an exception on a
        // settings screen.
        $threw = $this->button(FakeHttp::happy(), static function (string $s): bool {
            throw new \RuntimeException('the config directory is read only');
        })->create(self::URL, Event::TYPES, []);

        self::assertTrue($threw->ok);
        self::assertStringContainsString('could not be saved', $threw->message);
    }

    /** An answer with no secret in it is the same problem, said differently. */
    public function testAnAnswerWithNoSecretNamesTheFieldToPasteItInto(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['data' => []]),
            FakeHttp::answer(200, ['object' => 'webhook', 'id' => 'wh_1']),
        ]);

        $result = $this->button($http)->create(self::URL, Event::TYPES, []);

        self::assertTrue($result->ok);
        self::assertStringContainsString('no signing secret', $result->message);
    }

    /** No key at all is answered without a round trip, and says where to put one. */
    public function testNoApiKeyIsAnsweredWithoutACall(): void
    {
        $http = new FakeHttp([]);

        $result = (new ResendSetup(new ResendApi($http), [], null))->create(self::URL, Event::TYPES, []);

        self::assertFalse($result->ok);
        self::assertSame([], $http->calls);
        self::assertStringContainsString('Email Resend plugin', $result->message);
    }

    /** A key handed in per call wins over the one in the plugin's config. */
    public function testAKeyPassedInWinsOverTheConfiguredOne(): void
    {
        $http = FakeHttp::happy();

        $this->button($http)->create(self::URL, Event::TYPES, ['api_key' => 're_a_full_access_key']);

        self::assertSame('Bearer re_a_full_access_key', $http->call(0)['headers']['Authorization']);
    }

    /** No address to register is answered without a round trip too. */
    public function testNoAddressIsAnsweredWithoutACall(): void
    {
        $http = new FakeHttp([]);

        $result = $this->button($http)->create('   ', Event::TYPES, []);

        self::assertFalse($result->ok);
        self::assertSame([], $http->calls);
    }

    /** The permission sentence is one string, shown before the press and after a refusal. */
    public function testThePermissionsNeededAreNamedInResendsVocabulary(): void
    {
        $needed = $this->button(new FakeHttp([]))->permissionsNeeded();

        self::assertSame(ResendSetup::PERMISSIONS, $needed);
        self::assertStringContainsString('Full access', $needed);
        self::assertStringContainsString('API Keys', $needed);
    }

    // ------------------------------------------------------------- internals

    /** @param (\Closure(string): bool)|null $saveSecret */
    private function button(FakeHttp $http, ?\Closure $saveSecret = null): ResendSetup
    {
        return new ResendSetup(
            new ResendApi($http),
            ['api_key' => 're_a_sending_key'],
            $saveSecret,
        );
    }
}
