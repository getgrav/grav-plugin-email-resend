<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendSmtpTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Symfony's Resend bridge, against the Symfony Mailer the Email plugin actually
 * ships.
 *
 * This is the one test here that is not about delivery reports, and it is the
 * one that would otherwise fail at a merchant's site rather than on a machine.
 * The bridge's own `composer.json` asks for Symfony Mailer 7, and the Email
 * plugin ships 5.4; the plugin resolves that by replacing `symfony/mailer` in
 * its own `composer.json`, so the bridge is installed on its own and runs
 * against whatever Mailer Grav has. That is a claim, and this is the test of
 * it: the harness here installs Mailer at the 5.4 line on purpose.
 *
 * What is checked is everything the bridge touches that changed between the two
 * lines — the abstract transport it extends, the payload it builds out of a
 * message, and the SMTP transport's constructor. If Symfony renames one of
 * those, this fails here rather than the first time somebody sends a campaign.
 */
final class TransportTest extends TestCase
{
    public function testTheApiTransportBuildsAgainstTheMailerGravShips(): void
    {
        $transport = new ResendApiTransport('re_a_key', new NeverCalledHttpClient());

        self::assertSame('resend+api://api.resend.com', (string)$transport);
    }

    public function testTheSmtpTransportIsResendsDocumentedHostUserAndPort(): void
    {
        $transport = new ResendSmtpTransport('re_a_key');

        // Resend's SMTP user is the literal word `resend` for every account and
        // the password is the API key, which is why this plugin asks for no
        // separate SMTP credentials.
        self::assertSame('smtp.resend.com', $transport->getStream()->getHost());
        self::assertSame(465, $transport->getStream()->getPort());
        self::assertSame('resend', $transport->getUsername());
        self::assertSame('re_a_key', $transport->getPassword());
    }

    /**
     * The custom headers a bulk sender depends on reach the request body.
     *
     * This is what `Capabilities::$customHeaders` and `$unsubscribeHeaders`
     * claim, and it is claimed about this exact class, so it is worth reading
     * out of it rather than out of the documentation. The payload builder is
     * private, so it is reached the way the transport reaches it — by sending
     * through an HTTP client that records the request instead of making it.
     */
    public function testCustomAndUnsubscribeHeadersReachTheRequestBody(): void
    {
        $client = new RecordingHttpClient();
        $transport = new ResendApiTransport('re_a_key', $client);

        $email = (new Email())
            ->from(new Address('news@example.com', 'Example Store'))
            ->to(new Address('jane@example.com'))
            ->subject('Sending this example')
            ->text('Hello')
            ->html('<p>Hello</p>');

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Grav-Send-Id', '41');
        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/u/abc>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $transport->send($email);

        $payload = $client->lastJson();

        self::assertSame('Sending this example', $payload['subject']);
        self::assertSame(['jane@example.com'], $payload['to']);
        self::assertSame('41', $payload['headers']['X-Grav-Send-Id']);
        self::assertSame('<https://example.com/u/abc>', $payload['headers']['List-Unsubscribe']);
        self::assertSame('List-Unsubscribe=One-Click', $payload['headers']['List-Unsubscribe-Post']);
    }

    /** The message id Resend mints is kept, which is what an event's `email_id` matches. */
    public function testTheProvidersOwnIdIsKept(): void
    {
        $client = new RecordingHttpClient(['id' => '56761188-7520-42d8-8898-ff6fc54ce618']);
        $transport = new ResendApiTransport('re_a_key', $client);

        $email = (new Email())
            ->from(new Address('news@example.com'))
            ->to(new Address('jane@example.com'))
            ->subject('Hello')
            ->text('Hello');

        $sent = $transport->send($email);

        self::assertInstanceOf(SentMessage::class, $sent);
        self::assertSame('56761188-7520-42d8-8898-ff6fc54ce618', $sent->getMessageId());
        self::assertInstanceOf(Envelope::class, $sent->getEnvelope());
    }
}
