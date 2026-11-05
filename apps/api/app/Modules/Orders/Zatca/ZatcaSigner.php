<?php

namespace App\Modules\Orders\Zatca;

use App\Modules\Orders\Support\ZatcaQr;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Signs an e-invoice with our EGS unit's key, the way ZATCA checks it:
 *
 * 1. the invoice hash (UblInvoice::hash) is signed with ECDSA (secp256k1,
 *    SHA-256) as raw bytes;
 * 2. the XAdES signed properties carry the signing time and our
 *    certificate's digest, issuer and serial number, and their digest is
 *    the hex SHA-256 of their exclusive canonical form, base64 encoded;
 * 3. the QR code gets tags 1 to 9.
 *
 * The signed properties are digested as they stand in the signed XML, so
 * the digest always matches what ZATCA reads; they keep the layout of
 * ZATCA's samples, so a byte-for-byte reading of them agrees as well.
 * The result is checked before it is returned: taking the signature out
 * again must give back the hash that was signed.
 */
final class ZatcaSigner
{
    /** The PIH of an EGS unit's first document: base64 of the hex SHA-256 of "0". */
    public const FIRST_PREVIOUS_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    private const PROPERTIES_DIGEST = '%SIGNED_PROPERTIES_DIGEST%';

    public function sign(ZatcaDocument $document, string $privateKeyPem, Certificate $certificate, ?CarbonImmutable $signedAt = null): SignedDocument
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new LogicException('The EGS unit\'s private key cannot be read.');
        }

        $hash = UblInvoice::hash(UblInvoice::unsigned($document));

        if (! openssl_sign((string) base64_decode($hash, true), $raw, $key, OPENSSL_ALGO_SHA256)) {
            throw new LogicException('The invoice could not be signed.');
        }

        $signature = base64_encode($raw);
        [$date, $time] = UblInvoice::issued($document);
        $qr = ZatcaQr::phaseTwo(
            (string) ($document->seller['legal_name_ar'] ?? ''),
            (string) ($document->seller['vat_number'] ?? ''),
            $date.'T'.$time,
            Money::sar($document->totalHalalas),
            Money::sar($document->vatHalalas),
            $hash,
            $signature,
            $certificate->publicKey,
            $certificate->caSignature,
        );

        $signingTime = ($signedAt ?? CarbonImmutable::now())->setTimezone((string) config('groupbuy.display_timezone'))->format('Y-m-d\TH:i:s');
        $xml = UblInvoice::signed($document, self::extensions($hash, $signature, $certificate, $signingTime), $qr);
        $xml = str_replace(self::PROPERTIES_DIGEST, self::propertiesDigest($xml), $xml);

        if (UblInvoice::hash($xml) !== $hash) {
            throw new LogicException("The signed XML of {$document->number} no longer matches its hash.");
        }

        return new SignedDocument($xml, $hash, $signature, $qr);
    }

    /**
     * Whether $signature is our key's signature of $hash (for checks and tests).
     */
    public static function verify(string $hash, string $signature, Certificate $certificate): bool
    {
        return openssl_verify((string) base64_decode($hash, true), (string) base64_decode($signature, true), $certificate->pem(), OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The exclusive canonical form of the signed properties in $xml: what
     * their digest covers.
     */
    public static function canonicalProperties(string $xml): string
    {
        $dom = UblInvoice::load($xml);
        $node = UblInvoice::xpath($dom)->query('//xades:SignedProperties')?->item(0);
        $canonical = $node?->C14N(true, false);

        if (! is_string($canonical)) {
            throw new LogicException('The signed properties are missing.');
        }

        return $canonical;
    }

    private static function propertiesDigest(string $xml): string
    {
        return base64_encode(hash('sha256', self::canonicalProperties($xml)));
    }

    private static function extensions(string $hash, string $signature, Certificate $certificate, string $signingTime): string
    {
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<ext:UBLExtensions>
    <ext:UBLExtension>
        <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
        <ext:ExtensionContent>
            <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
                <sac:SignatureInformation>
                    <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
                    <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
                    <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
                        <ds:SignedInfo>
                            <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                            <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                            <ds:Reference Id="invoiceSignedData" URI="">
                                <ds:Transforms>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                        <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath>
                                    </ds:Transform>
                                    <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                </ds:Transforms>
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>'.$e($hash).'</ds:DigestValue>
                            </ds:Reference>
                            <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                <ds:DigestValue>'.self::PROPERTIES_DIGEST.'</ds:DigestValue>
                            </ds:Reference>
                        </ds:SignedInfo>
                        <ds:SignatureValue>'.$e($signature).'</ds:SignatureValue>
                        <ds:KeyInfo>
                            <ds:X509Data>
                                <ds:X509Certificate>'.$e($certificate->base64).'</ds:X509Certificate>
                            </ds:X509Data>
                        </ds:KeyInfo>
                        <ds:Object>
                            <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                <xades:SignedProperties Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>'.$e($signingTime).'</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                                    <ds:DigestValue>'.$e($certificate->digest()).'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName>'.$e($certificate->issuer).'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber>'.$e($certificate->serial).'</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>
                            </xades:QualifyingProperties>
                        </ds:Object>
                    </ds:Signature>
                </sac:SignatureInformation>
            </sig:UBLDocumentSignatures>
        </ext:ExtensionContent>
    </ext:UBLExtension>
</ext:UBLExtensions>';
    }
}
