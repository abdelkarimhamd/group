<?php

namespace App\Modules\Orders\Zatca;

/**
 * A signed e-invoice: the XML sent to ZATCA, its hash (the next
 * document's PIH), the signature and the QR code printed on the PDF.
 */
final class SignedDocument
{
    public function __construct(
        public readonly string $xml,
        public readonly string $hash,
        public readonly string $signature,
        public readonly string $qr,
    ) {}
}
