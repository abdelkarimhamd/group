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
    /** Codes thrown from a variable, so the scan cannot read them: file => codes. */
    private const VARIABLE_CODES = [
        'app/Modules/Identity/Otp/OtpService.php' => ['otp_invalid', 'otp_expired', 'otp_attempts_exhausted'],
    ];

    #[Test]
    public function every_error_code_has_a_message_in_both_languages(): void
    {
        $codes = [];
        $variable = [];
        $files = [base_path('bootstrap/app.php')];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all("/new ApiException\\(\\s*\\d+\\s*,\\s*'([a-z0-9_]+)'/", $source, $constructed);
            preg_match_all("/ApiException::\\w+\\(\\s*'([a-z0-9_]+)'/", $source, $named);
            // The framework errors rendered in bootstrap/app.php.
            preg_match_all("/\\\$apiError\\(\\s*'([a-z0-9_]+)'/", $source, $framework);

            foreach ([...$constructed[1], ...$named[1], ...$framework[1]] as $code) {
                $codes[$code] = true;
            }

            if (preg_match('/new ApiException\\(\\s*\\d+\\s*,\\s*\\$|ApiException::\\w+\\(\\s*\\$/', $source) === 1) {
                $variable[] = ltrim(str_replace(base_path(), '', $path), '/');
            }
        }

        sort($variable);
        $this->assertSame(array_keys(self::VARIABLE_CODES), $variable, 'A code thrown from a variable: list its codes in VARIABLE_CODES.');

        foreach (self::VARIABLE_CODES as $listed) {
            foreach ($listed as $code) {
                $codes[$code] = true;
            }
        }

        foreach (['same_admin', 'not_found', 'unauthenticated'] as $known) {
            $this->assertArrayHasKey($known, $codes, 'The scan missed a known code; check the patterns.');
        }

        foreach (['ar', 'en'] as $locale) {
            $missing = array_values(array_filter(array_keys($codes), fn (string $code) => ! trans()->has("messages.errors.{$code}", $locale, false)));
            $this->assertSame([], $missing, "Error codes without a message in {$locale}.");
        }
    }
}
