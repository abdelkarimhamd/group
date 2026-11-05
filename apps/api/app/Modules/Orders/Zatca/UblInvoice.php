<?php

namespace App\Modules\Orders\Zatca;

use App\Support\Money\Money;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/**
 * The UBL 2.1 XML of a simplified tax invoice, credit note or debit note
 * as ZATCA's e-invoicing (phase 2) wants it, and its hash.
 *
 * The body is written without whitespace between elements. The hash is
 * taken over the canonical XML without the UBL extensions (the signature),
 * the cac:Signature element and the QR reference, the parts added after
 * signing; with no whitespace around them, taking them out of the signed
 * XML gives back exactly what was hashed, which is what ZATCA checks.
 *
 * Lines are VAT-inclusive at issue. Each line's net and VAT are the ones
 * printed on the invoice; when its net does not divide by the quantity,
 * the price is given for the whole quantity (BaseQuantity), so quantity
 * times price is the line net exactly.
 */
final class UblInvoice
{
    private const NAMESPACES = 'xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
        .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
        .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"'
        .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"';

    /**
     * The document before signing: what the hash covers.
     */
    public static function unsigned(ZatcaDocument $document): string
    {
        return self::build($document, null, null);
    }

    /**
     * The signed document: the UBL extensions block (the XAdES signature)
     * and the QR code added to what was hashed.
     */
    public static function signed(ZatcaDocument $document, string $extensions, string $qr): string
    {
        return self::build($document, $extensions, $qr);
    }

    /**
     * The invoice hash: base64 of the SHA-256 of the canonical XML (C14N)
     * without ext:UBLExtensions, cac:Signature and the QR reference.
     */
    public static function hash(string $xml): string
    {
        $dom = self::load($xml);
        $xpath = self::xpath($dom);

        foreach ([
            '/i:Invoice/ext:UBLExtensions',
            '/i:Invoice/cac:Signature',
            "/i:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']",
        ] as $path) {
            foreach (iterator_to_array($xpath->query($path) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $canonical = $dom->documentElement?->C14N(false, false);

        if (! is_string($canonical)) {
            throw new InvalidArgumentException('The invoice XML cannot be canonicalized.');
        }

        return base64_encode(hash('sha256', $canonical, true));
    }

    public static function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        if (! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException('Not well-formed invoice XML.');
        }

        return $dom;
    }

    public static function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('i', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        return $xpath;
    }

    /**
     * "2026-11-05" and "14:03:27": the issue date and time in Riyadh, as
     * printed on the invoice and put in the QR code.
     *
     * @return array{0: string, 1: string}
     */
    public static function issued(ZatcaDocument $document): array
    {
        $local = $document->issuedAt->setTimezone((string) config('groupbuy.display_timezone'));

        return [$local->format('Y-m-d'), $local->format('H:i:s')];
    }

    private static function build(ZatcaDocument $d, ?string $extensions, ?string $qr): string
    {
        [$date, $time] = self::issued($d);
        $s = $d->seller;
        $percent = self::percent($d->vatRateBp);
        $tax = '<cac:TaxCategory><cbc:ID schemeID="UN/ECE 5305" schemeAgencyID="6">S</cbc:ID><cbc:Percent>'.$percent.'</cbc:Percent>'
            .'<cac:TaxScheme><cbc:ID schemeID="UN/ECE 5153" schemeAgencyID="6">VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory>';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<Invoice '.self::NAMESPACES.'>'
            .($extensions ?? '')
            .'<cbc:ProfileID>reporting:1.0</cbc:ProfileID>'
            .self::el('cbc:ID', $d->number)
            .self::el('cbc:UUID', $d->uuid)
            .self::el('cbc:IssueDate', $date)
            .self::el('cbc:IssueTime', $time)
            .'<cbc:InvoiceTypeCode name="'.ZatcaDocument::SIMPLIFIED.'">'.$d->typeCode.'</cbc:InvoiceTypeCode>'
            .'<cbc:DocumentCurrencyCode>SAR</cbc:DocumentCurrencyCode>'
            .'<cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>'
            .($d->billingReference === null ? '' : '<cac:BillingReference><cac:InvoiceDocumentReference>'.self::el('cbc:ID', $d->billingReference).'</cac:InvoiceDocumentReference></cac:BillingReference>')
            .'<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID>'.self::el('cbc:UUID', (string) $d->icv).'</cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.self::text($d->previousHash).'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'
            .($qr === null ? '' : '<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.self::text($qr).'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>')
            .($extensions === null ? '' : '<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>')
            .'<cac:AccountingSupplierParty><cac:Party>'
            .'<cac:PartyIdentification><cbc:ID schemeID="CRN">'.self::text((string) ($s['cr_number'] ?? '')).'</cbc:ID></cac:PartyIdentification>'
            .'<cac:PostalAddress>'
            .self::el('cbc:StreetName', (string) ($s['street'] ?? ''))
            .self::el('cbc:BuildingNumber', (string) ($s['building_number'] ?? ''))
            .self::el('cbc:CitySubdivisionName', (string) ($s['district'] ?? ''))
            .self::el('cbc:CityName', (string) ($s['city'] ?? ''))
            .self::el('cbc:PostalZone', (string) ($s['postal_code'] ?? ''))
            .'<cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country>'
            .'</cac:PostalAddress>'
            .'<cac:PartyTaxScheme>'.self::el('cbc:CompanyID', (string) ($s['vat_number'] ?? '')).'<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'
            .'<cac:PartyLegalEntity>'.self::el('cbc:RegistrationName', (string) ($s['legal_name_ar'] ?? '')).'</cac:PartyLegalEntity>'
            .'</cac:Party></cac:AccountingSupplierParty>'
            // A simplified invoice names no buyer.
            .'<cac:AccountingCustomerParty></cac:AccountingCustomerParty>'
            // 48: bank card (Moyasar).
            .'<cac:PaymentMeans><cbc:PaymentMeansCode>48</cbc:PaymentMeansCode>'
            .($d->reason === null ? '' : self::el('cbc:InstructionNote', $d->reason))
            .'</cac:PaymentMeans>'
            .'<cac:TaxTotal>'.self::amount('cbc:TaxAmount', $d->vatHalalas)
            .'<cac:TaxSubtotal>'.self::amount('cbc:TaxableAmount', $d->netHalalas).self::amount('cbc:TaxAmount', $d->vatHalalas).$tax.'</cac:TaxSubtotal>'
            .'</cac:TaxTotal>'
            // The VAT again in the tax currency (also SAR).
            .'<cac:TaxTotal>'.self::amount('cbc:TaxAmount', $d->vatHalalas).'</cac:TaxTotal>'
            .'<cac:LegalMonetaryTotal>'
            .self::amount('cbc:LineExtensionAmount', $d->netHalalas)
            .self::amount('cbc:TaxExclusiveAmount', $d->netHalalas)
            .self::amount('cbc:TaxInclusiveAmount', $d->totalHalalas)
            .self::amount('cbc:AllowanceTotalAmount', 0)
            .self::amount('cbc:PrepaidAmount', 0)
            .self::amount('cbc:PayableAmount', $d->totalHalalas)
            .'</cac:LegalMonetaryTotal>';

        foreach (array_values($d->lines) as $i => $line) {
            $qty = (int) $line['qty'];
            $net = (int) $line['net_halalas'];
            $wholeQuantity = $net % $qty !== 0;
            $name = trim((string) ($line['description_ar'] ?? '')) ?: (string) ($line['description_en'] ?? '');

            $xml .= '<cac:InvoiceLine>'
                .self::el('cbc:ID', (string) ($i + 1))
                .'<cbc:InvoicedQuantity unitCode="PCE">'.$qty.'</cbc:InvoicedQuantity>'
                .self::amount('cbc:LineExtensionAmount', $net)
                .'<cac:TaxTotal>'.self::amount('cbc:TaxAmount', (int) $line['vat_halalas']).self::amount('cbc:RoundingAmount', (int) $line['total_halalas']).'</cac:TaxTotal>'
                .'<cac:Item>'.self::el('cbc:Name', $name)
                .'<cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>'.$percent.'</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory>'
                .'</cac:Item>'
                .'<cac:Price>'.self::amount('cbc:PriceAmount', $wholeQuantity ? $net : intdiv($net, $qty))
                .($wholeQuantity ? '<cbc:BaseQuantity unitCode="PCE">'.$qty.'</cbc:BaseQuantity>' : '')
                .'</cac:Price>'
                .'</cac:InvoiceLine>';
        }

        return $xml.'</Invoice>';
    }

    private static function el(string $name, string $value): string
    {
        return '<'.$name.'>'.self::text($value).'</'.$name.'>';
    }

    private static function amount(string $name, int $halalas): string
    {
        return '<'.$name.' currencyID="SAR">'.Money::sar($halalas)->decimal().'</'.$name.'>';
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 1500 basis points: "15.00". */
    private static function percent(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }
}
