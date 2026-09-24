<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailResend\Provider\ResendInbound;
use Grav\Plugin\EmailResend\Provider\ResendInboundProvider;
use Grav\Plugin\EmailResend\Provider\ResendProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mail received through Resend: the Svix check on the `email.received`
 * webhook, the reference it becomes, and the download in the worker, all from
 * Resend's documented payloads.
 */
final class ResendInboundTest extends TestCase
{
    private const NOW = 1758622500;

    private const EMAIL_ID = '56761188-7520-42d8-8898-ff6fc54ce618';

    // ------------------------------------------------------------ discovery

    public function testTheInboundProviderIsFoundByTheGatewayAndThePlainOneIsNot(): void
    {
        self::assertNotInstanceOf(InboundCapable::class, new ResendProvider());
        self::assertInstanceOf(InboundCapable::class, new ResendInboundProvider());

        $registry = new ProviderRegistry();
        $registry->add(new ResendInboundProvider(['api_key' => 're_key']));
        $receiver = (new InboundGateway(null, $registry))->receiver('resend');

        self::assertInstanceOf(ResendInbound::class, $receiver);
        self::assertInstanceOf(InboundReceiver::class, $receiver);
        self::assertSame('Resend', $receiver->label());
        self::assertSame(['inbound_signing_secret'], $receiver->verificationKeys());
        self::assertSame(1024 * 1024, $receiver->maxBytes());
    }

    /**
     * The plain provider's file names no inbound type, which is what lets it
     * load beside an Email plugin from before inbound mail.
     */
    public function testThePlainProviderNamesNoInboundTypeInItsDeclaration(): void
    {
        $source = (string)file_get_contents(\dirname(__DIR__, 3) . '/classes/Provider/ResendProvider.php');

        self::assertStringNotContainsString('use Grav\\Plugin\\Email\\Providers\\Inbound', $source);
        self::assertMatchesRegularExpression('/^class ResendProvider implements Provider$/m', $source);
    }

    /**
     * Beside an Email plugin with no inbound types at all, in a fresh process:
     * the plain provider loads and answers, and the plugin's check picks it.
     */
    public function testThePlainProviderLoadsWhereTheEmailPluginHasNoInbound(): void
    {
        $emailRoot = self::emailRoot();
        $root = \dirname(__DIR__, 3);
        $script = sprintf(<<<'PHP'
            spl_autoload_register(static function (string $class): void {
                foreach (['Grav\\Plugin\\Email\\Providers\\' => %s, 'Grav\\Plugin\\EmailResend\\' => %s] as $prefix => $dir) {
                    if (str_starts_with($class, $prefix) && !str_starts_with($class, 'Grav\\Plugin\\Email\\Providers\\Inbound\\')) {
                        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) { require $file; }
                    }
                }
            });
            $class = interface_exists(Grav\Plugin\Email\Providers\Inbound\InboundCapable::class)
                ? Grav\Plugin\EmailResend\Provider\ResendInboundProvider::class
                : Grav\Plugin\EmailResend\Provider\ResendProvider::class;
            $provider = new $class(['api_key' => 're_x']);
            $provider->capabilities();
            $provider->instructions();
            echo get_class($provider);
            PHP,
            var_export($emailRoot . '/classes/Providers/', true),
            var_export($root . '/classes/', true)
        );

        $output = [];
        exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame(ResendProvider::class, implode("\n", $output));
    }

    public function testInstructionsNameTheAddressTheEventAndTheSecret(): void
    {
        $text = (new ResendInbound())->instructions('https://example.com/_helpdesk/inbound/resend/abc');

        self::assertStringContainsString('https://example.com/_helpdesk/inbound/resend/abc', $text);
        self::assertStringContainsString('email.received', $text);
        self::assertStringContainsString('MX record', $text);
        self::assertStringContainsString('whsec_', $text);
        self::assertStringContainsString('Inbound signing secret', $text);
    }

    // ---------------------------------------------------------------- verify

    public function testASignedWebhookIsVerified(): void
    {
        $body = self::fixture('email.received.json');
        $secret = self::secret();

        $verdict = self::receiver()->verify(self::request($body, self::svix($body, $secret, self::NOW)), [
            'inbound_signing_secret' => $secret,
        ]);

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed);
    }

    public function testABadSignatureIsRefused(): void
    {
        $body = self::fixture('email.received.json');
        $headers = self::svix($body, self::secret('someone-else'), self::NOW);

        $verdict = self::receiver()->verify(self::request($body, $headers), ['inbound_signing_secret' => self::secret()]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);
    }

    public function testATamperedBodyIsRefused(): void
    {
        $body = self::fixture('email.received.json');
        $secret = self::secret();
        $headers = self::svix($body, $secret, self::NOW);

        $tampered = str_replace('56761188', '99999999', $body);
        $verdict = self::receiver()->verify(self::request($tampered, $headers), ['inbound_signing_secret' => $secret]);

        self::assertFalse($verdict->ok);
    }

    public function testAStaleTimestampIsRefused(): void
    {
        $body = self::fixture('email.received.json');
        $secret = self::secret();
        $headers = self::svix($body, $secret, self::NOW - 301);

        $verdict = self::receiver()->verify(self::request($body, $headers), ['inbound_signing_secret' => $secret]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('tolerance', $verdict->reason);
    }

    /**
     * The delivery-report secret is a different webhook's secret, and signing
     * with it is not a pass on the inbound webhook when no inbound secret is set.
     */
    public function testWithNoInboundSecretEverythingIsRefused(): void
    {
        $body = self::fixture('email.received.json');
        $secret = self::secret();

        $verdict = self::receiver()->verify(self::request($body, self::svix($body, $secret, self::NOW)), [
            'signing_secret' => $secret,
        ]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('inbound signing secret', $verdict->reason);
    }

    public function testTheGatewayRunsTheSameCheck(): void
    {
        $body = self::fixture('email.received.json');
        $secret = self::secret();
        $registry = new ProviderRegistry();
        $registry->add(new ResendInboundProvider());
        $gateway = new InboundGateway(null, $registry);

        $refused = $gateway->receive('resend', self::request($body, self::svix($body, self::secret('x'), time())), [
            'inbound_signing_secret' => $secret,
        ]);
        $accepted = $gateway->receive('resend', self::request($body, self::svix($body, $secret, time())), [
            'inbound_signing_secret' => $secret,
        ]);

        self::assertSame(401, $refused->status);
        self::assertSame(200, $accepted->status);
        self::assertCount(1, $accepted->references());
    }

    // ----------------------------------------------------------------- parse

    public function testTheReceivedEventBecomesAReference(): void
    {
        $payload = self::receiver()->parse(self::request(self::fixture('email.received.json')), []);

        self::assertFalse($payload->unreadable, $payload->note);
        self::assertCount(1, $payload->items);

        $ref = $payload->items[0];
        self::assertInstanceOf(InboundReference::class, $ref);
        self::assertSame('resend', $ref->receiver);
        self::assertSame(self::EMAIL_ID, $ref->id);
        self::assertSame('onboarding@resend.dev', $ref->meta['from']);
        self::assertSame(['delivered@resend.dev'], $ref->meta['to']);
        self::assertSame(['forwarded@example.com'], $ref->meta['received_for']);
        self::assertSame('Sending this example', $ref->meta['subject']);
        self::assertSame('111-222-333@email.example.com', $ref->meta['message_id']);
        self::assertSame(1, $ref->meta['attachments']);
        self::assertNotFalse(json_encode($ref->meta), 'meta is stored as JSON');
    }

    public function testAnotherEventIsNothing(): void
    {
        $body = (string)file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/webhooks/resend/email.delivered.json');
        $payload = self::receiver()->parse(self::request($body), []);

        self::assertFalse($payload->unreadable);
        self::assertTrue($payload->isEmpty());
        self::assertStringContainsString('email.delivered', $payload->note);
    }

    public function testRubbishIsUnreadableAndNeverThrown(): void
    {
        foreach (['', 'not json', '[1,2,3]', '{"type":"email.received"}', '{"type":"email.received","data":{"email_id":["x"]}}'] as $body) {
            $payload = self::receiver()->parse(self::request($body), []);

            self::assertTrue($payload->unreadable, 'unreadable: ' . $body);
            self::assertTrue($payload->isEmpty());
        }
    }

    // ----------------------------------------------------------------- fetch

    public function testFetchDownloadsTheRawMessageWithoutTheApiKey(): void
    {
        $email = self::json('retrieve-received-email.json');
        $http = new FakeHttp([FakeHttp::answer(200, $email)]);
        $rawUrl = $email['raw']['download_url'];
        $download = new FakeDownload([$rawUrl => FakeDownload::ok(self::fixture('raw-message.eml'))]);

        $message = (new ResendInbound(['api_key' => 're_plugin'], $http, $download))
            ->fetch(new InboundReference('resend', self::EMAIL_ID), []);

        $call = $http->call(0);
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.resend.com/emails/receiving/' . self::EMAIL_ID . '?html_format=cid', $call['url']);
        self::assertSame('Bearer re_plugin', $call['headers']['Authorization']);

        self::assertCount(1, $download->calls);
        self::assertSame($rawUrl, $download->calls[0]['url']);

        self::assertInstanceOf(InboundMessage::class, $message);
        self::assertSame('resend', $message->receiver);
        self::assertSame(self::fixture('raw-message.eml'), $message->raw);
        self::assertSame(self::EMAIL_ID, $message->providerId);
        self::assertSame('cab1234@mail.example.net', $message->messageId);
        self::assertSame('hd.1042.7@support.example.com', $message->inReplyTo);
        self::assertSame(['hd.1042.1@support.example.com', 'hd.1042.7@support.example.com'], $message->references);
        self::assertSame('customer@example.net', $message->from->email);
        self::assertSame('Casey Customer', $message->from->name);
        self::assertSame('Re: [#1042] Printer on fire', $message->subject);
        self::assertSame('Still on fire.', trim((string)$message->text));
        self::assertStringContainsString('Still on fire.', (string)$message->html);
        self::assertSame(['support+t8f2k@support.example.com'], $message->envelopeTo);
        self::assertSame('t8f2k', $message->to[0]->detail());
        self::assertCount(1, $message->attachments);
        self::assertSame('notes.txt', $message->attachments[0]->filename);
        self::assertSame("Smoke everywhere\n", $message->attachments[0]->bytes());

        // Resend's own results win over the Authentication-Results the message
        // carried, which said spf=fail; unknown is left out.
        self::assertSame(['spf' => 'pass', 'dkim' => 'none'], $message->auth);
    }

    public function testTheApiKeyInTheConfigPassedWinsOverTheOneTheProviderWasBuiltWith(): void
    {
        $email = self::json('retrieve-received-email.json');
        $http = new FakeHttp([FakeHttp::answer(200, $email)]);
        $download = new FakeDownload([$email['raw']['download_url'] => FakeDownload::ok(self::fixture('raw-message.eml'))]);

        (new ResendInbound(['api_key' => 're_plugin'], $http, $download))
            ->fetch(new InboundReference('resend', self::EMAIL_ID), ['api_key' => 're_passed']);

        self::assertSame('Bearer re_passed', $http->call(0)['headers']['Authorization']);
    }

    public function testWithNoRawFileTheMessageIsBuiltFromTheFieldsAndAttachmentsAreDownloaded(): void
    {
        $email = self::json('retrieve-received-email-no-raw.json');
        $list = self::json('list-attachments.json');
        $id = $email['id'];
        $http = new FakeHttp([FakeHttp::answer(200, $email), FakeHttp::answer(200, $list)]);
        $download = new FakeDownload([
            $list['data'][0]['download_url'] => FakeDownload::ok("\x89PNG\r\n\x1a\n"),
            $list['data'][1]['download_url'] => FakeDownload::ok('%PDF-1.7' . "\n"),
        ]);

        $message = (new ResendInbound(['api_key' => 're_plugin'], $http, $download))
            ->fetch(new InboundReference('resend', $id), []);

        self::assertSame('https://api.resend.com/emails/receiving/' . $id . '/attachments', $http->call(1)['url']);
        self::assertSame('Bearer re_plugin', $http->call(1)['headers']['Authorization']);

        self::assertNull($message->raw);
        self::assertSame('resend', $message->receiver);
        self::assertSame($id, $message->providerId);
        self::assertSame('cab5678@mail.example.net', $message->messageId);
        self::assertSame('hd.1042.7@support.example.com', $message->inReplyTo);
        self::assertSame(['hd.1042.1@support.example.com', 'hd.1042.7@support.example.com'], $message->references);
        self::assertSame('Casey Customer', $message->from->name);
        self::assertSame('customer@example.net', $message->from->email);
        self::assertSame('support+t8f2k@support.example.com', $message->to[0]->email);
        self::assertSame('Support', $message->to[0]->name);
        self::assertSame('other@example.net', $message->cc[0]->email);
        self::assertSame('customer@example.net', $message->envelopeFrom);
        self::assertSame(strtotime('Fri, 03 Apr 2026 22:13:40 +0000'), $message->date);
        self::assertSame("Still on fire.\n", $message->text);
        self::assertStringContainsString('cid:img001', (string)$message->html);
        self::assertSame('multipart/mixed', $message->contentType);
        self::assertSame('<CAB5678@mail.example.net>', $message->header('Message-ID'));
        self::assertSame(['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'temperror'], $message->auth);

        self::assertCount(2, $message->attachments);
        [$image, $pdf] = $message->attachments;
        self::assertSame('avatar.png', $image->filename);
        self::assertTrue($image->inline);
        self::assertSame('img001', $image->contentId);
        self::assertSame(8, $image->size);
        self::assertSame('document.pdf', $pdf->filename, 'directories are taken off a sender\'s filename');
        self::assertFalse($pdf->inline);
        self::assertNull($pdf->contentId);
        self::assertSame("%PDF-1.7\n", $pdf->bytes());
    }

    public function testFetchThrowsWhenThereIsNoKeySoTheWorkerCanSayWhy(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');

        (new ResendInbound([], new FakeHttp()))->fetch(new InboundReference('resend', self::EMAIL_ID), []);
    }

    public function testFetchThrowsOnARefusalInResendsOwnWords(): void
    {
        $http = new FakeHttp([FakeHttp::answer(404, ['statusCode' => 404, 'name' => 'not_found', 'message' => 'Email not found'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resend answered 404 for the received message: Email not found.');

        (new ResendInbound(['api_key' => 're_key'], $http))->fetch(new InboundReference('resend', self::EMAIL_ID), []);
    }

    public function testFetchThrowsWhenTheRawDownloadFailsSoItIsRetried(): void
    {
        $email = self::json('retrieve-received-email.json');
        $http = new FakeHttp([FakeHttp::answer(200, $email)]);
        $download = new FakeDownload([$email['raw']['download_url'] => ['status' => 403, 'body' => 'expired', 'error' => '']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('raw message');

        (new ResendInbound(['api_key' => 're_key'], $http, $download))->fetch(new InboundReference('resend', self::EMAIL_ID), []);
    }

    // --------------------------------------------------------------- helpers

    private static function emailRoot(): string
    {
        $file = (new \ReflectionClass(InboundGateway::class))->getFileName();

        return \dirname((string)$file, 4);
    }

    private static function receiver(): ResendInbound
    {
        return new ResendInbound([], null, null, static fn (): int => self::NOW);
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/inbound/resend/' . $name);
    }

    /** @return array<string, mixed> */
    private static function json(string $name): array
    {
        return json_decode(self::fixture($name), true, 32, \JSON_THROW_ON_ERROR);
    }

    private static function secret(string $salt = 'default'): string
    {
        return 'whsec_' . base64_encode(str_pad($salt, 24, '-inbound-secret'));
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
    private static function request(string $body, array $headers = []): InboundRequest
    {
        return new InboundRequest(headers: $headers + ['content-type' => 'application/json'], body: $body);
    }
}
