<?php

namespace App\Modules\Orders\Zatca;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A certificate ZATCA issued to our EGS unit (a CSID), and what the
 * signature block and the phase 2 QR code need from it. ZATCA hands it
 * out as the base64 of the certificate's base64 text (binarySecurityToken);
 * we keep the certificate's base64 text.
 */
final class Certificate
{
    /** Short names for the attribute types ZATCA's CAs use, in RFC 4514 style. */
    private const NAMES = [
        '2.5.4.3' => 'CN',
        '2.5.4.5' => 'SERIALNUMBER',
        '2.5.4.6' => 'C',
        '2.5.4.7' => 'L',
        '2.5.4.8' => 'ST',
        '2.5.4.10' => 'O',
        '2.5.4.11' => 'OU',
        '0.9.2342.19200300.100.1.1' => 'UID',
        '0.9.2342.19200300.100.1.25' => 'DC',
        '1.2.840.113549.1.9.1' => 'E',
    ];

    private function __construct(
        /** The certificate's base64 text, without PEM armour or line breaks. */
        public readonly string $base64,
        /** "CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local": most specific first. */
        public readonly string $issuer,
        /** The serial number in decimal. */
        public readonly string $serial,
        /** The public key (SubjectPublicKeyInfo) in DER, QR tag 8. */
        public readonly string $publicKey,
        /** The issuing CA's signature over the certificate, QR tag 9. */
        public readonly string $caSignature,
        public readonly CarbonImmutable $validTo,
    ) {}

    /**
     * From the certificate's base64 text, with or without PEM armour.
     */
    public static function fromBase64(string $text): self
    {
        $base64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $text);
        $der = base64_decode($base64, true);

        if ($der === false || $der === '') {
            throw new InvalidArgumentException('The certificate is not base64.');
        }

        $pem = self::armour($base64);
        $parsed = openssl_x509_parse($pem);
        $key = openssl_pkey_get_public($pem);

        if ($parsed === false || $key === false) {
            throw new InvalidArgumentException('The certificate cannot be read.');
        }

        $details = openssl_pkey_get_details($key);
        $publicKey = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) ($details['key'] ?? '')), true);

        $certificate = Der::one($der);
        $parts = Der::children($certificate['value']);

        if ($certificate['tag'] !== Der::SEQUENCE || count($parts) !== 3 || $parts[2]['tag'] !== Der::BIT_STRING) {
            throw new InvalidArgumentException('The certificate is not an X.509 certificate.');
        }

        $tbs = Der::children($parts[0]['value']);
        // [0] version is optional; the issuer follows serial and algorithm.
        $issuer = $tbs[$tbs[0]['tag'] === 0xA0 ? 3 : 2];

        return new self(
            $base64,
            self::name($issuer['value']),
            (string) $parsed['serialNumber'],
            (string) $publicKey,
            // A BIT STRING starts with its count of unused bits (0 here).
            substr($parts[2]['value'], 1),
            CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
        );
    }

    /**
     * From ZATCA's binarySecurityToken: the base64 of the certificate's base64 text.
     */
    public static function fromToken(string $token): self
    {
        $text = base64_decode($token, true);

        if ($text === false) {
            throw new InvalidArgumentException('The security token is not base64.');
        }

        return self::fromBase64($text);
    }

    /**
     * The token form again, which goes into the Authorization header.
     */
    public function token(): string
    {
        return base64_encode($this->base64);
    }

    public function pem(): string
    {
        return self::armour($this->base64);
    }

    /**
     * The certificate digest in the signed properties: base64 of the hex
     * SHA-256 of the certificate's base64 text (ZATCA's convention).
     */
    public function digest(): string
    {
        return base64_encode(hash('sha256', $this->base64));
    }

    private static function armour(string $base64): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($base64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * An X.509 Name as text, last RDN first, joined with ", ".
     */
    private static function name(string $sequence): string
    {
        $parts = [];

        foreach (Der::children($sequence) as $rdn) {
            foreach (Der::children($rdn['value']) as $attribute) {
                [$type, $value] = Der::children($attribute['value']);
                $oid = Der::oid($type['value']);
                $parts[] = (self::NAMES[$oid] ?? $oid).'='.$value['value'];
            }
        }

        return implode(', ', array_reverse($parts));
    }
}
