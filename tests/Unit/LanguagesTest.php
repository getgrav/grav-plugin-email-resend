<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailResend\Tests\Unit;

use Grav\Plugin\EmailResend\Provider\ResendProvider;
use PHPUnit\Framework\TestCase;

/**
 * The English in `languages/en.yaml` and the English written into the provider
 * are the same words.
 *
 * They have to be said twice — once in the class, so a test and a site with no
 * language service get a sentence rather than a key, and once in the language
 * file, so a translator has something to translate. Two copies of a paragraph
 * drift, and the way it shows up is a merchant on an English site reading last
 * month's instructions while the code does something else. This is the cheap
 * guard against that.
 */
final class LanguagesTest extends TestCase
{
    public function testTheLanguageFileCarriesTheSameEnglishAsTheProvider(): void
    {
        $yaml = (string)file_get_contents(\dirname(__DIR__, 2) . '/languages/en.yaml');
        $provider = new ResendProvider();

        self::assertStringContainsString('PLUGIN_EMAIL_RESEND:', $yaml);
        self::assertStringContainsString($provider->instructions(), $yaml);
        self::assertStringContainsString($provider->capabilities()->echoNote, $yaml);
    }

    /** Both keys the provider looks up exist, spelled the way it looks them up. */
    public function testEveryKeyTheProviderAsksForIsInTheFile(): void
    {
        $yaml = (string)file_get_contents(\dirname(__DIR__, 2) . '/languages/en.yaml');

        foreach ([ResendProvider::INSTRUCTIONS_KEY, ResendProvider::ECHO_NOTE_KEY] as $key) {
            [$namespace, $name] = explode('.', $key, 2);

            self::assertStringContainsString($namespace . ':', $yaml);
            self::assertStringContainsString($name . ':', $yaml);
        }
    }
}
