<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailResend\Provider\ResendReports;
use Grav\Plugin\EmailResend\Provider\SiteTag;
use Grav\Plugin\EmailResend\Provider\TaggingHttpClient;
use Grav\Plugin\EmailResend\Tests\Unit\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Resend posts every event on a team to every webhook on it, so each site tags
 * what it sends and turns away events tagged for another site.
 */
final class SiteTagTest extends TestCase
{
    public function testTheValueIsStableForOneSiteAndDiffersBetweenSites(): void
    {
        $one = SiteTag::value('/home/grav/webapps/site', 'box');

        self::assertSame($one, SiteTag::value('/home/grav/webapps/site/', 'box'));
        self::assertNotSame($one, SiteTag::value('/home/grav/webapps/newsletter', 'box'));
        self::assertNotSame($one, SiteTag::value('/home/grav/webapps/site', 'other-box'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_\-]{1,256}$/', $one, 'Resend\'s tag alphabet');
    }

    public function testEveryMessageSentOverTheApiCarriesTheSiteAndSendIdTags(): void
    {
        $client = new RecordingHttpClient();
        $transport = new ResendApiTransport('re_a_key', new TaggingHttpClient('s-this-site', $client));

        $email = (new Email())
            ->from(new Address('news@example.com'))
            ->to(new Address('jane@example.com'))
            ->subject('Hello')
            ->text('Hello');
        $email->getHeaders()->addTextHeader('X-Grav-Send-Id', '41');

        $transport->send($email);

        $json = $client->lastJson();
        self::assertSame([
            ['name' => 'grav-site', 'value' => 's-this-site'],
            ['name' => 'grav-send-id', 'value' => '41'],
        ], $json['tags']);
        self::assertSame('41', $json['headers']['X-Grav-Send-Id'], 'the header still goes too');
    }

    public function testAMessageWithNoSendIdCarriesOnlyTheSiteTag(): void
    {
        $json = TaggingHttpClient::tagged(['to' => ['jane@example.com']], 's-this-site');

        self::assertSame([['name' => 'grav-site', 'value' => 's-this-site']], $json['tags']);
    }

    public function testOnlyTheEmailsCallIsTouched(): void
    {
        $client = new RecordingHttpClient();
        (new TaggingHttpClient('s-this-site', $client))->request('POST', 'https://api.resend.com/webhooks', ['json' => ['x' => 1]]);

        self::assertSame(['x' => 1], $client->lastJson());
    }

    public function testAnEventFromAnotherSiteIsTurnedAway(): void
    {
        $reports = new ResendReports(null, 's-this-site');

        $foreign = $reports->parse(self::delivered(['grav-site' => 's-another-site']));
        self::assertSame([], $foreign->events);
        self::assertFalse($foreign->unreadable);
        self::assertStringContainsString('another site', $foreign->note);

        self::assertCount(1, $reports->parse(self::delivered(['grav-site' => 's-this-site']))->events);
        self::assertCount(1, $reports->parse(self::delivered([]))->events, 'untagged mail is kept');
        self::assertCount(1, (new ResendReports())->parse(self::delivered(['grav-site' => 's-another-site']))->events,
            'a receiver that knows no site keeps everything');
    }

    public function testTheTagIsReadAsAListToo(): void
    {
        self::assertSame('s-x', SiteTag::in([['name' => 'grav-site', 'value' => 's-x']]));
        self::assertNull(SiteTag::in(['category' => 'confirm_email']));
        self::assertNull(SiteTag::in('nope'));
    }

    /** @param array<string, string> $tags */
    private static function delivered(array $tags): WebhookRequest
    {
        $body = json_encode([
            'type' => 'email.delivered',
            'created_at' => '2026-10-21T22:00:00.000Z',
            'data' => [
                'email_id' => '56761188-7520-42d8-8898-ff6fc54ce618',
                'to' => ['jane@example.com'],
                'tags' => (object)$tags,
            ],
        ], JSON_THROW_ON_ERROR);

        return new WebhookRequest('POST', '/hook', [], [], $body);
    }
}
