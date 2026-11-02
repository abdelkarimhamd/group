<?php

namespace App\Modules\Payments\Gateway;

/**
 * The definite answer to a capture, void or refund: it worked, or the
 * provider refused it. An unknown outcome is a GatewayException instead.
 */
final class GatewayResult
{
    /**
     * @param  array<string, mixed>  $payload  The provider's response, for the record.
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $providerReference,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function succeeded(?string $providerReference, array $payload = []): self
    {
        return new self(true, $providerReference, null, null, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function refused(string $errorCode, string $errorMessage, array $payload = []): self
    {
        return new self(false, null, $errorCode, mb_substr($errorMessage, 0, 500), $payload);
    }
}
