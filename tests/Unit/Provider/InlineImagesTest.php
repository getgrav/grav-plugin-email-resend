<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit\Provider;

use Grav\Plugin\EmailResend\Provider\TaggingHttpClient;
use Grav\Plugin\EmailResend\Tests\Unit\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A picture shown in the message (`<img src="cid:…">`) has to reach Resend with
 * its content id, or it arrives as a plain attachment and the message shows a
 * broken image.
 */
final class InlineImagesTest extends TestCase
{
    public function testAnEmbeddedPictureGoesWithItsContentIdAndAFileDoesNot(): void
    {
        $client = new RecordingHttpClient();
        $transport = new ResendApiTransport('re_a_key', new TaggingHttpClient('s-this-site', $client));

        $email = (new Email())
            ->from(new Address('help@example.com'))
            ->to(new Address('jane@example.com'))
            ->subject('Re: Your request')
            ->html('<p>Here it is:</p><img src="cid:c-home-desktop.jpg" alt="">')
            ->embed('JPEGBYTES', 'c-home-desktop.jpg', 'image/jpeg')
            ->attach('%PDF', 'invoice.pdf', 'application/pdf');

        $transport->send($email);

        $byName = [];
        foreach ($client->lastJson()['attachments'] as $a) {
            $byName[$a['filename']] = $a;
        }
        self::assertSame('c-home-desktop.jpg', $byName['c-home-desktop.jpg']['content_id'] ?? null, 'the picture is inline');
        self::assertArrayNotHasKey('content_id', $byName['invoice.pdf'], 'the file stays an attachment');
    }

    public function testAMessageWithoutPicturesIsUnchanged(): void
    {
        $json = ['html' => '<p>No pictures</p>', 'attachments' => [['filename' => 'a.pdf', 'content' => 'eA==']]];

        self::assertSame($json, TaggingHttpClient::inlined($json));
        self::assertSame(['text' => 'plain'], TaggingHttpClient::inlined(['text' => 'plain']));
    }

    public function testAContentIdAlreadyThereIsKept(): void
    {
        $json = ['html' => '<img src="cid:logo">', 'attachments' => [['filename' => 'logo', 'content' => 'eA==', 'content_id' => 'brand']]];

        self::assertSame('brand', TaggingHttpClient::inlined($json)['attachments'][0]['content_id']);
    }
}
