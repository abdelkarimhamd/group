<?php

namespace Tests\Fakes;

use App\Modules\Orders\Zatca\Certificate;
use RuntimeException;

/**
 * A stand-in for ZATCA's certificate authority: issues a certificate for
 * a CSR, named like ZATCA's test CA, so signing can be checked end to end.
 */
final class ZatcaTestCa
{
    private static ?self $instance = null;

    private string $config;

    private \OpenSSLAsymmetricKey $key;

    private \OpenSSLCertificate $certificate;

    private function __construct()
    {
        $this->config = (string) tempnam(sys_get_temp_dir(), 'zatca-ca');
        file_put_contents($this->config, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ ext ]\nbasicConstraints = CA:FALSE\n");
        // A fixed CA made with the openssl command line (PHP cannot name a
        // subject with several DCs): /DC=local/DC=gov/DC=extgazt/CN=TSZEINVOICE-SubCA-1.
        $fixtures = dirname(__DIR__).'/Fixtures/zatca';
        $key = openssl_pkey_get_private((string) file_get_contents($fixtures.'/test-ca.key'));
        $certificate = openssl_x509_read((string) file_get_contents($fixtures.'/test-ca.pem'));

        if ($key === false || $certificate === false) {
            throw new RuntimeException('The test CA cannot be read: '.openssl_error_string());
        }

        $this->key = $key;
        $this->certificate = $certificate;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * A certificate for the CSR (PEM), as its base64 text.
     */
    public function issue(string $csrPem, int $serial = 1234567890123): string
    {
        $certificate = openssl_csr_sign($csrPem, $this->certificate, $this->key, 365, ['config' => $this->config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], $serial);

        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('The test CA could not issue a certificate: '.openssl_error_string());
        }

        return Certificate::fromBase64($pem)->base64;
    }

    /**
     * ZATCA's answer to a CSID request: the base64 of the certificate's base64 text.
     */
    public function token(string $csrPem, int $serial = 1234567890123): string
    {
        return base64_encode($this->issue($csrPem, $serial));
    }

    /**
     * A key and a certificate for it, without the CSR round trip.
     *
     * @return array{0: string, 1: Certificate} private key PEM, certificate
     */
    public function keyAndCertificate(): array
    {
        $key = self::key();
        openssl_pkey_export($key, $pem);
        $csr = openssl_csr_new(['commonName' => 'TST-886431145-399999999900003'], $key, ['config' => $this->config, 'digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);

        return [$pem, Certificate::fromBase64($this->issue($csrPem))];
    }

    private static function key(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);

        if ($key === false) {
            throw new RuntimeException('No secp256k1 key: '.openssl_error_string());
        }

        return $key;
    }
}
