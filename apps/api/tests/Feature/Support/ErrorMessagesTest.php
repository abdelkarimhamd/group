<?php

namespace Tests\Feature\Support;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Every error code the API can answer with has a message in Arabic and in
 * English (contract: Error.message is localized, for display), so a client
 * never shows a translation key.
 */
class ErrorMessagesTest extends TestCase
{
    #[Test]
    public function every_error_code_has_a_message_in_both_languages(): void
    {
        $codes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/new ApiException\\(\\s*\\d+\\s*,\\s*'([a-z0-9_]+)'/", $source, $constructed);
            preg_match_all("/ApiException::\\w+\\(\\s*'([a-z0-9_]+)'/", $source, $named);

            foreach ([...$constructed[1], ...$named[1]] as $code) {
                $codes[$code] = true;
            }
        }

        $this->assertArrayHasKey('same_admin', $codes, 'The scan found no codes; check the patterns.');

        foreach (['ar', 'en'] as $locale) {
            $missing = array_values(array_filter(array_keys($codes), fn (string $code) => ! trans()->has("messages.errors.{$code}", $locale, false)));
            $this->assertSame([], $missing, "Error codes without a message in {$locale}.");
        }
    }
}
