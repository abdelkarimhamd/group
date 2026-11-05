<?php

namespace App\Modules\Orders\Zatca;

use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A new EGS unit's key (secp256k1) and its certificate signing request
 * in the form ZATCA's onboarding wants: the certificate template name
 * for the environment, and in the subject alternative name the unit's
 * serial number, our VAT number, the invoice types it issues (0100:
 * simplified only), its location and the industry.
 */
final class ZatcaCsr
{
    private const TEMPLATES = [
        'developer-portal' => 'TSTZATCA-Code-Signing',
        'simulation' => 'PREZATCA-Code-Signing',
        'core' => 'ZATCA-Code-Signing',
    ];

    /**
     * @param  array{solution_name: string, model: string, organization_unit: string, location: string, industry: string}  $egs
     * @param  array{legal_name_en: string, vat_number: string}  $seller
     * @return array{private_key: string, csr: string, serial: string, common_name: string}
     */
    public static function make(string $environment, array $egs, array $seller): array
    {
        $template = self::TEMPLATES[$environment] ?? throw new RuntimeException("Unknown ZATCA environment [{$environment}].");
        $serial = sprintf('1-%s|2-%s|3-%s', self::plain($egs['solution_name']), self::plain($egs['model']), Str::uuid());
        $commonName = mb_substr(self::plain($egs['solution_name']).'-'.$seller['vat_number'], 0, 64);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);

        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('No secp256k1 key could be made: '.openssl_error_string());
        }

        $config = (string) tempnam(sys_get_temp_dir(), 'zatca-csr');

        try {
            file_put_contents($config, implode("\n", [
                'oid_section = OIDs',
                '[ OIDs ]',
                'certificateTemplateName = 1.3.6.1.4.1.311.20.2',
                '[ req ]',
                'distinguished_name = dn',
                'req_extensions = req_ext',
                '[ dn ]',
                '[ req_ext ]',
                'certificateTemplateName = ASN1:PRINTABLESTRING:'.$template,
                'subjectAltName = dirName:alt_names',
                '[ alt_names ]',
                'SN = '.self::value($serial),
                'UID = '.self::value($seller['vat_number']),
                'title = 0100',
                'registeredAddress = '.self::value($egs['location']),
                'businessCategory = '.self::value($egs['industry']),
                '',
            ]));

            $csr = openssl_csr_new([
                'countryName' => 'SA',
                'organizationalUnitName' => $egs['organization_unit'],
                'organizationName' => $seller['legal_name_en'],
                'commonName' => $commonName,
            ], $key, ['config' => $config, 'req_extensions' => 'req_ext', 'digest_alg' => 'sha256']);

            if ($csr === false || $csr === true || ! openssl_csr_export($csr, $pem)) {
                throw new RuntimeException('The CSR could not be made: '.openssl_error_string());
            }
        } finally {
            @unlink($config);
        }

        return ['private_key' => $privateKey, 'csr' => $pem, 'serial' => $serial, 'common_name' => $commonName];
    }

    /** Letters, digits, spaces, dots and dashes: safe in the serial's |-separated parts. */
    private static function plain(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9 .\-]/', '', $value)) ?: 'EGS';
    }

    /** A value for the openssl config: no line breaks, its special characters escaped. */
    private static function value(string $value): string
    {
        return (string) preg_replace('/([\\\\"#$])/', '\\\\$1', str_replace(["\r", "\n"], ' ', trim($value)));
    }
}
