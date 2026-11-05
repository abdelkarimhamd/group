<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Support\ZatcaQr;
use App\Modules\Orders\Zatca\Certificate;
use App\Modules\Orders\Zatca\UblInvoice;
use App\Modules\Orders\Zatca\ZatcaDocument;
use App\Modules\Orders\Zatca\ZatcaSigner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\ZatcaTestCa;
use Tests\TestCase;

/**
 * The signed e-invoice (ZATCA phase 2): UBL XML, invoice hash, ECDSA
 * signature, XAdES signed properties and the nine-tag QR code.
 */
class ZatcaSigningTest extends TestCase
{
    private const SELLER = [
        'legal_name_ar' => 'شركة الشراء الجماعي',
        'legal_name_en' => 'Group Buy Co',
        'vat_number' => '310123456700003',
        'cr_number' => '1010010000',
        'street' => 'King Fahd Road',
        'building_number' => '1234',
        'district' => 'Al Olaya',
        'city' => 'Riyadh',
        'postal_code' => '12214',
    ];

    #[Test]
    public function an_invoice_is_signed_so_that_its_hash_and_signature_check_out(): void
    {
        [$key, $certificate] = ZatcaTestCa::instance()->keyAndCertificate();
        $document = $this->document();

        $signed = (new ZatcaSigner)->sign($document, $key, $certificate, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));

        // The hash covers the invoice without signature, cac:Signature and QR.
        $this->assertSame(UblInvoice::hash(UblInvoice::unsigned($document)), $signed->hash);
        $this->assertSame($signed->hash, UblInvoice::hash($signed->xml));
        $this->assertTrue(ZatcaSigner::verify($signed->hash, $signed->signature, $certificate));

        $dom = UblInvoice::load($signed->xml);
        $x = UblInvoice::xpath($dom);
        $value = fn (string $path) => $x->evaluate("string({$path})");
        $this->assertSame('INV-2026-000123', $value('/i:Invoice/cbc:ID'));
        $this->assertSame('388', $value('/i:Invoice/cbc:InvoiceTypeCode'));
        $this->assertSame('0200000', $value('/i:Invoice/cbc:InvoiceTypeCode/@name'));
        // Riyadh time, three hours ahead of UTC.
        $this->assertSame(['2026-11-05', '14:03:27'], [$value('/i:Invoice/cbc:IssueDate'), $value('/i:Invoice/cbc:IssueTime')]);
        $this->assertSame('7', $value("/i:Invoice/cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID"));
        $this->assertSame(ZatcaSigner::FIRST_PREVIOUS_HASH, $value("/i:Invoice/cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject"));
        $this->assertSame($signed->qr, $value("/i:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject"));
        $this->assertSame($signed->hash, $value("//ds:Reference[@Id='invoiceSignedData']/ds:DigestValue"));
        $this->assertSame($signed->signature, $value('//ds:SignatureValue'));
        $this->assertSame($certificate->base64, $value('//ds:X509Certificate'));
        $this->assertSame('1010010000', $value("//cac:AccountingSupplierParty//cac:PartyIdentification/cbc:ID[@schemeID='CRN']"));
        $this->assertSame('شركة الشراء الجماعي', $value('//cac:PartyLegalEntity/cbc:RegistrationName'));
        $this->assertSame(['40.63', '270.87', '15.00'], [
            $value('/i:Invoice/cac:TaxTotal[cac:TaxSubtotal]/cbc:TaxAmount'),
            $value('/i:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'),
            $value('/i:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent'),
        ]);
        $this->assertSame('40.63', $value('/i:Invoice/cac:TaxTotal[not(cac:TaxSubtotal)]/cbc:TaxAmount'));
        $this->assertSame(['270.87', '311.50', '311.50'], [
            $value('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
            $value('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'),
            $value('//cac:LegalMonetaryTotal/cbc:PayableAmount'),
        ]);
        // 300.00 for three: the net (260.87) does not divide by 3, so the price is for all three.
        $this->assertSame(['260.87', '3'], [$value('/i:Invoice/cac:InvoiceLine[1]/cac:Price/cbc:PriceAmount'), $value('/i:Invoice/cac:InvoiceLine[1]/cac:Price/cbc:BaseQuantity')]);
        $this->assertSame(['10.00', ''], [$value('/i:Invoice/cac:InvoiceLine[2]/cac:Price/cbc:PriceAmount'), $value('/i:Invoice/cac:InvoiceLine[2]/cac:Price/cbc:BaseQuantity')]);

        // The signed properties name our certificate as ZATCA's samples do.
        $this->assertSame('CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local', $value('//ds:X509IssuerName'));
        $this->assertSame('1234567890123', $value('//ds:X509SerialNumber'));
        $this->assertSame(base64_encode(hash('sha256', $certificate->base64)), $value('//xades:CertDigest/ds:DigestValue'));
        $this->assertSame('2026-11-05T14:00:00', $value('//xades:SigningTime'));
        $properties = ZatcaSigner::canonicalProperties($signed->xml);
        $this->assertSame($this->zatcaSignedProperties('2026-11-05T14:00:00', $certificate), $properties);
        $this->assertSame(base64_encode(hash('sha256', $properties)), $value("//ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue"));

        // QR: tags 1 to 9.
        $qr = ZatcaQr::decode($signed->qr);
        $this->assertSame(range(1, 9), array_keys($qr));
        $this->assertSame(['شركة الشراء الجماعي', '310123456700003', '2026-11-05T14:03:27', '311.50', '40.63'], [$qr[1], $qr[2], $qr[3], $qr[4], $qr[5]]);
        $this->assertSame([$signed->hash, $signed->signature, $certificate->publicKey, $certificate->caSignature], [$qr[6], $qr[7], $qr[8], $qr[9]]);
    }

    #[Test]
    public function a_credit_note_names_the_invoice_it_corrects_and_why(): void
    {
        [$key, $certificate] = ZatcaTestCa::instance()->keyAndCertificate();
        $signed = (new ZatcaSigner)->sign($this->document(ZatcaDocument::CREDIT_NOTE), $key, $certificate);
        $x = UblInvoice::xpath(UblInvoice::load($signed->xml));

        $this->assertSame('381', $x->evaluate('string(/i:Invoice/cbc:InvoiceTypeCode)'));
        $this->assertSame('INV-2026-000100', $x->evaluate('string(//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)'));
        $this->assertSame('Refund on order ORD-2611-000001', $x->evaluate('string(//cac:PaymentMeans/cbc:InstructionNote)'));
        $this->assertSame($signed->hash, UblInvoice::hash($signed->xml));
    }

    #[Test]
    public function the_hash_changes_with_any_part_of_the_invoice_but_not_with_the_signature(): void
    {
        $document = $this->document();
        $hash = UblInvoice::hash(UblInvoice::unsigned($document));

        $this->assertNotSame($hash, UblInvoice::hash(UblInvoice::unsigned($this->document(icv: 8))));
        $this->assertSame($hash, UblInvoice::hash(UblInvoice::signed($document, '<ext:UBLExtensions><ext:UBLExtension/></ext:UBLExtensions>', 'cXI=')));
    }

    #[Test]
    public function a_certificate_is_read_from_zatcas_token(): void
    {
        [, $certificate] = ZatcaTestCa::instance()->keyAndCertificate();

        $again = Certificate::fromToken($certificate->token());

        $this->assertSame($certificate->base64, $again->base64);
        $this->assertSame("-----BEGIN CERTIFICATE-----\n", substr($again->pem(), 0, 28));
        // The public key is a SubjectPublicKeyInfo for secp256k1 (88 bytes).
        $this->assertSame(88, strlen($again->publicKey));
        $this->assertSame(0x30, ord($again->caSignature[0]));
        $this->assertTrue($again->validTo->isFuture());
    }

    #[Test]
    public function a_long_certificate_serial_is_written_in_decimal(): void
    {
        // ZATCA's serials run to about 150 bits, where OpenSSL gives hex.
        $certificate = Certificate::fromBase64((string) file_get_contents(base_path('tests/Fixtures/zatca/large-serial.pem')));
        $key = (string) file_get_contents(base_path('tests/Fixtures/zatca/large-serial.key'));

        $this->assertSame('148433760045349177002444173467528991025267737332', $certificate->serial);

        $signed = (new ZatcaSigner)->sign($this->document(), $key, $certificate, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));
        $dom = UblInvoice::load($signed->xml);

        $this->assertSame('148433760045349177002444173467528991025267737332', UblInvoice::xpath($dom)->evaluate('string(//ds:X509SerialNumber)'));
        $this->assertTrue(ZatcaSigner::verify($signed->hash, $signed->signature, $certificate));
    }

    private function document(string $type = ZatcaDocument::INVOICE, int $icv = 7): ZatcaDocument
    {
        $note = $type !== ZatcaDocument::INVOICE;

        return new ZatcaDocument(
            number: $note ? 'CRN-2026-000124' : 'INV-2026-000123',
            uuid: '8d487816-70b8-4ade-a618-9d620b73814a',
            typeCode: $type,
            issuedAt: CarbonImmutable::parse('2026-11-05 11:03:27', 'UTC'),
            seller: self::SELLER,
            lines: [
                ['description_ar' => 'سماعات - أسود', 'description_en' => 'Headphones - Black', 'qty' => 3, 'unit_price_halalas' => 10000, 'net_halalas' => 26087, 'vat_halalas' => 3913, 'total_halalas' => 30000],
                ['description_ar' => 'رسوم التوصيل', 'description_en' => 'Delivery', 'qty' => 1, 'unit_price_halalas' => 1150, 'net_halalas' => 1000, 'vat_halalas' => 150, 'total_halalas' => 1150],
            ],
            netHalalas: 27087,
            vatHalalas: 4063,
            totalHalalas: 31150,
            vatRateBp: 1500,
            icv: $icv,
            previousHash: ZatcaSigner::FIRST_PREVIOUS_HASH,
            billingReference: $note ? 'INV-2026-000100' : null,
            reason: $note ? 'Refund on order ORD-2611-000001' : null,
        );
    }

    /**
     * The signed properties as ZATCA's samples hash them: exclusive
     * canonical form, so each namespace is declared where it is first used.
     */
    private function zatcaSignedProperties(string $signingTime, Certificate $certificate): string
    {
        $ds = 'xmlns:ds="http://www.w3.org/2000/09/xmldsig#"';

        return '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="xadesSignedProperties">
                                    <xades:SignedSignatureProperties>
                                        <xades:SigningTime>'.$signingTime.'</xades:SigningTime>
                                        <xades:SigningCertificate>
                                            <xades:Cert>
                                                <xades:CertDigest>
                                                    <ds:DigestMethod '.$ds.' Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"></ds:DigestMethod>
                                                    <ds:DigestValue '.$ds.'>'.$certificate->digest().'</ds:DigestValue>
                                                </xades:CertDigest>
                                                <xades:IssuerSerial>
                                                    <ds:X509IssuerName '.$ds.'>'.$certificate->issuer.'</ds:X509IssuerName>
                                                    <ds:X509SerialNumber '.$ds.'>'.$certificate->serial.'</ds:X509SerialNumber>
                                                </xades:IssuerSerial>
                                            </xades:Cert>
                                        </xades:SigningCertificate>
                                    </xades:SignedSignatureProperties>
                                </xades:SignedProperties>';
    }
}
