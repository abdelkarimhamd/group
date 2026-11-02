<?php

namespace Tests\Unit\Support;

use App\Support\Phone\SaudiMobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SaudiMobileTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function numbers(): array
    {
        return [
            'E.164' => ['+966512345678', '+966512345678'],
            'local' => ['0512345678', '+966512345678'],
            'without trunk zero' => ['512345678', '+966512345678'],
            'country code without plus' => ['966512345678', '+966512345678'],
            'international prefix' => ['00966512345678', '+966512345678'],
            'trunk zero after country code' => ['+966 0512345678', '+966512345678'],
            'spaces and dashes' => ['+966 51-234 5678', '+966512345678'],
            'Arabic-Indic digits' => ['٠٥١٢٣٤٥٦٧٨', '+966512345678'],
            'Persian digits' => ['۰۵۱۲۳۴۵۶۷۸', '+966512345678'],
            'landline' => ['0112345678', null],
            'too short' => ['051234567', null],
            'too long' => ['05123456789', null],
            'foreign mobile' => ['+971512345678', null],
            'letters' => ['05123abc78', null],
            'empty' => ['', null],
            'not a string' => [512345678, null],
            'array' => [['0512345678'], null],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function it_normalizes_saudi_mobiles_to_e164(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, SaudiMobile::normalize($input));
    }

    #[Test]
    public function it_masks_all_but_the_last_four_digits(): void
    {
        $this->assertSame('+9665••••5678', SaudiMobile::mask('+966512345678'));
    }
}
