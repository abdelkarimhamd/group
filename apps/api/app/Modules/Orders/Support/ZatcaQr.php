<?php

namespace App\Modules\Orders\Support;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The QR code a simplified tax invoice must carry (ZATCA e-invoicing):
 * seller name, VAT number, time of issue, total with VAT and the VAT, each
 * as tag-length-value, base64 encoded. Phase 2 (integration) adds the
 * invoice hash, its signature, our public key and the CA's signature on
 * our certificate, so a reader can check the invoice offline.
 */
final class ZatcaQr
{
    public static function encode(string $sellerName, string $vatNumber, CarbonImmutable $issuedAt, Money $total, Money $vat): string
    {
        return self::tlv([
            1 => $sellerName,
            2 => $vatNumber,
            3 => $issuedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => self::amount($total),
            5 => self::amount($vat),
        ]);
    }

    /**
     * Tags 1 to 9 of a signed simplified invoice. The time is the issue
     * date and time as written in the XML; the hash and the signature go
     * in as their base64 text, the public key (DER) and the CA's signature
     * as bytes.
     */
    public static function phaseTwo(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        Money $total,
        Money $vat,
        string $invoiceHash,
        string $signature,
        string $publicKey,
        string $caSignature,
    ): string {
        return self::tlv([
            1 => $sellerName,
            2 => $vatNumber,
            3 => $timestamp,
            4 => self::amount($total),
            5 => self::amount($vat),
            6 => $invoiceHash,
            7 => $signature,
            8 => $publicKey,
            9 => $caSignature,
        ]);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private static function tlv(array $fields): string
    {
        $tlv = '';

        foreach ($fields as $tag => $value) {
            $length = strlen($value);

            if ($length > 255) {
                throw new InvalidArgumentException("QR field {$tag} is longer than 255 bytes.");
            }

            $tlv .= chr($tag).chr($length).$value;
        }

        return base64_encode($tlv);
    }

    /**
     * @return array<int, string> The fields by tag, for tests and checks.
     */
    public static function decode(string $payload): array
    {
        $bytes = base64_decode($payload, true);

        if ($bytes === false) {
            throw new InvalidArgumentException('Not a base64 QR payload.');
        }

        $fields = [];

        for ($i = 0; $i < strlen($bytes);) {
            $tag = ord($bytes[$i]);
            $length = ord($bytes[$i + 1]);
            $fields[$tag] = substr($bytes, $i + 2, $length);
            $i += 2 + $length;
        }

        return $fields;
    }

    /**
     * "105.00": the amount in riyals with two decimals, without floats.
     */
    private static function amount(Money $money): string
    {
        $minor = $money->minor;
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }
}
