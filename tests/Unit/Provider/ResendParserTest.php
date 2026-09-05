<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailResend\Provider\ResendReports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Resend's own documented sample payloads, read field by field.
 *
 * The fixtures are Resend's own, copied from `resend.com/docs/webhooks` and
 * carried here from the KahunaCart Newsletter add-on, where this parser used to
 * live. They are the point of the exercise: a provider that quietly renames a
 * field is caught by a fixture and by nothing else, and a timestamp in a format
 * nobody parsed reads as zero and is then stamped with the receiver's clock,
 * which looks exactly like a working chart until somebody compares it with the
 * dashboard.
 */
final class ResendParserTest extends TestCase
{
    /**
     * @return \Generator<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function samples(): \Generator
    {
        yield 'delivered' => ['email.delivered', [
            'type' => Event::DELIVERED,
            'hard' => null,
            'email' => 'delivered@resend.dev',
            'message_id' => '111-222-333@email.example.com',
            'provider_id' => '56761188-7520-42d8-8898-ff6fc54ce618',
            'reason' => null,
            'send_id' => null,
        ]];

        // Resend runs on SES, so `bounce.type` is Amazon's vocabulary and
        // `Permanent` is the only one of the three words that is a hard bounce.
        yield 'permanent bounce' => ['email.bounced', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'delivered@resend.dev',
            'message_id' => '111-222-333@email.example.com',
            'reason' => 'Suppressed: The recipient\'s email address is on the suppression list because it has a '
                . 'recent history of producing hard bounces.',
        ]];

        // `Transient` is a full mailbox or a busy server, and suppressing an
        // address on one of those is how a store loses a customer who was
        // behind a full mailbox for an afternoon.
        yield 'transient bounce' => ['email.bounced-transient', [
            'type' => Event::BOUNCED,
            'hard' => false,
            'reason' => 'MailboxFull: The recipient\'s mailbox is full and cannot accept messages now.',
        ]];

        yield 'complaint' => ['email.complained', [
            'type' => Event::COMPLAINED,
            'hard' => null,
            'email' => 'delivered@resend.dev',
            // No bounce block on a complaint, so the event says what it is
            // rather than carrying an empty reason.
            'reason' => 'marked as spam',
        ]];

        yield 'open' => ['email.opened', ['type' => Event::OPENED, 'reason' => null]];
        yield 'click' => ['email.clicked', ['type' => Event::CLICKED, 'reason' => null]];

        // A delay is followed either by a delivery or by a bounce. Acting on it
        // would suppress addresses that are about to receive their mail.
        yield 'delay is not a bounce' => ['email.delivery_delayed', null];

        yield 'sent is not delivered' => ['email.sent', null];
        yield 'failed is not acted on' => ['email.failed', null];
        yield 'suppressed is not acted on' => ['email.suppressed', null];
    }

    /**
     * @param array<string, mixed>|null $expected null means "read no events"
     */
    #[DataProvider('samples')]
    public function testTheDocumentedSampleBecomesTheContractsEvent(string $fixture, ?array $expected): void
    {
        $payload = (new ResendReports())->parse(self::request($fixture));

        self::assertFalse($payload->unreadable, "{$fixture} should be readable");

        if ($expected === null) {
            self::assertTrue($payload->isEmpty(), "{$fixture} should be skipped");
            self::assertStringContainsString('act on', $payload->note, $fixture);

            return;
        }

        self::assertCount(1, $payload->events, "{$fixture} should read exactly one event");

        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "{$fixture}: {$field}");
        }
    }

    /**
     * The outer `created_at` is the moment, not the inner one.
     *
     * They are different things — the outer is when the webhook event was made,
     * the inner is when the email was made — and they are minutes or hours
     * apart on a bounce. Reading the wrong one puts every point on a delivery
     * chart at the moment the campaign was sent.
     */
    public function testTheMomentIsTheEventsOwnAndNotTheEmails(): void
    {
        $payload = (new ResendReports())->parse(self::request('email.bounced'));

        self::assertSame(strtotime('2026-11-22T23:41:12Z'), $payload->events[0]->at);
    }

    /** A `Message-ID` arrives in angle brackets, which are the grammar rather than the id. */
    public function testTheMessageIdLosesItsAngleBrackets(): void
    {
        $payload = (new ResendReports())->parse(self::request('email.delivered'));

        self::assertSame('111-222-333@email.example.com', $payload->events[0]->messageId);
    }

    /**
     * The one merchant-settable value Resend hands back.
     *
     * Not a header — Resend echoes none — but a tag, whose key can only hold
     * letters, digits, underscores and hyphens, which is why it is not spelled
     * like the header.
     */
    public function testASendIdComesBackAsATagOrNotAtAll(): void
    {
        $reports = new ResendReports();

        $tagged = $reports->parse(self::request('email.delivered-tagged'))->events[0];
        self::assertSame('41', $tagged->sendId);

        // And the address is lower-cased with its display name taken off, so a
        // suppression list has one spelling of it.
        self::assertSame('jane@example.com', $tagged->email);

        $plain = $reports->parse(self::request('email.delivered'))->events[0];
        self::assertNull($plain->sendId);
    }

    /**
     * A body that is not what Resend sends is a note and no events, never an
     * exception.
     *
     * `parse()` runs on a public address anybody can post to, and every one of
     * these providers treats a 4xx as a reason to retry for days.
     */
    public function testABodyThatIsNotResendsIsUnreadableRatherThanAnException(): void
    {
        $reports = new ResendReports();

        foreach (['', '   ', 'not json at all', '<html><body>502</body></html>', '{"type":', '[{"type":"x"}]'] as $body) {
            $payload = $reports->parse(new WebhookRequest('POST', '/hook', [], [], $body));

            self::assertTrue($payload->unreadable, var_export($body, true));
            self::assertSame([], $payload->events);
            self::assertNotSame('', $payload->note);
        }
    }

    /** An event with a type nobody recognises is skipped, and the note says which. */
    public function testAnUnknownEventNamesItselfInTheNote(): void
    {
        $payload = (new ResendReports())->parse(
            new WebhookRequest('POST', '/hook', [], [], '{"type":"contact.created","data":{}}')
        );

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
        self::assertStringContainsString('contact.created', $payload->note);
    }

    /** The five words this provider can report, and no others. */
    public function testItReportsTheFiveEventsItSubscribesTo(): void
    {
        $events = (new ResendReports())->events();

        self::assertSame(
            [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED],
            $events
        );

        foreach ($events as $event) {
            self::assertContains($event, Event::TYPES);
        }
    }

    public function testTheSendHeaderIsNamedOnceAndInOnePlace(): void
    {
        self::assertSame('X-KahunaCart-Send', (new ResendReports())->sendHeader());
    }

    // ------------------------------------------------------------- internals

    private static function request(string $fixture): WebhookRequest
    {
        return new WebhookRequest('POST', '/hook', [], ['content-type' => 'application/json'], self::body($fixture));
    }

    private static function body(string $fixture): string
    {
        $path = \dirname(__DIR__, 2) . "/Fixtures/webhooks/resend/{$fixture}.json";
        self::assertFileExists($path, "there is no documented sample at {$path}");

        return (string)file_get_contents($path);
    }
}
