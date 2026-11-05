<?php

namespace App\Modules\Orders\Zatca;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * ZATCA's e-invoicing API (Fatoora), version 2: CSIDs for onboarding,
 * compliance checks, and reporting simplified invoices. Built from
 * ZATCA's published developer documentation; it could not be called from
 * where it was written, so onboard against the developer portal or the
 * simulation environment first (`zatca:onboard` runs ZATCA's own
 * compliance checks before any production CSID is issued).
 *
 * Each call is logged before it is sent. A document carries its own
 * idempotency: ZATCA knows it by UUID and hash and answers a repeat with 409.
 */
final class FatooraClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public static function forEnvironment(string $environment): self
    {
        $url = config("groupbuy.zatca.base_urls.{$environment}");

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException("Unknown ZATCA environment [{$environment}].");
        }

        return new self(rtrim($url, '/'), (int) config('groupbuy.zatca.timeout_seconds'));
    }

    /**
     * POST /compliance: a compliance CSID for the CSR, with the OTP from the Fatoora portal.
     *
     * @return array{request_id: string, token: string, secret: string}
     *
     * @throws FatooraRefused|FatooraUnavailable
     */
    public function complianceCsid(string $csrPem, string $otp): array
    {
        Log::info('zatca.compliance_csid.sending', ['url' => $this->baseUrl]);

        return $this->csid($this->send(
            fn (PendingRequest $http) => $http->withHeaders(['OTP' => $otp])->post($this->baseUrl.'/compliance', ['csr' => base64_encode($csrPem)]),
        ), 'compliance CSID');
    }

    /**
     * POST /compliance/invoices: one of the sample documents onboarding must pass.
     *
     * @throws FatooraUnavailable
     */
    public function complianceCheck(Certificate $certificate, string $secret, string $uuid, SignedDocument $document): FatooraAnswer
    {
        Log::info('zatca.compliance_check.sending', ['uuid' => $uuid]);

        return $this->answer($this->send(
            fn (PendingRequest $http) => $http->withBasicAuth($certificate->token(), $secret)->post($this->baseUrl.'/compliance/invoices', self::document($uuid, $document)),
        ));
    }

    /**
     * POST /production/csids: the production CSID once the compliance checks passed.
     *
     * @return array{request_id: string, token: string, secret: string}
     *
     * @throws FatooraRefused|FatooraUnavailable
     */
    public function productionCsid(Certificate $complianceCertificate, string $complianceSecret, string $complianceRequestId): array
    {
        Log::info('zatca.production_csid.sending', ['compliance_request_id' => $complianceRequestId]);

        return $this->csid($this->send(
            fn (PendingRequest $http) => $http->withBasicAuth($complianceCertificate->token(), $complianceSecret)
                ->post($this->baseUrl.'/production/csids', ['compliance_request_id' => $complianceRequestId]),
        ), 'production CSID');
    }

    /**
     * POST /invoices/reporting/single: report a signed simplified invoice or note.
     *
     * @throws FatooraUnavailable
     */
    public function report(Certificate $certificate, string $secret, string $uuid, SignedDocument $document): FatooraAnswer
    {
        return $this->answer($this->send(
            fn (PendingRequest $http) => $http->withBasicAuth($certificate->token(), $secret)
                ->withHeaders(['Clearance-Status' => '0'])
                ->post($this->baseUrl.'/invoices/reporting/single', self::document($uuid, $document)),
        ));
    }

    /**
     * @return array{invoiceHash: string, uuid: string, invoice: string}
     */
    private static function document(string $uuid, SignedDocument $document): array
    {
        return ['invoiceHash' => $document->hash, 'uuid' => $uuid, 'invoice' => base64_encode($document->xml)];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call(Http::acceptJson()->asJson()->timeout($this->timeoutSeconds)->withHeaders([
                'Accept-Version' => 'V2',
                'Accept-Language' => 'en',
            ]));
        } catch (Throwable $e) {
            throw new FatooraUnavailable('Fatoora could not be reached: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Reporting and compliance answers: 200, 202, 400 and 409 are ZATCA's
     * verdict on the document; anything else is retried.
     */
    private function answer(Response $response): FatooraAnswer
    {
        $body = $response->json();
        $answer = new FatooraAnswer($response->status(), is_array($body) ? $body : []);

        if (! $answer->accepted() && ! $answer->refused()) {
            throw new FatooraUnavailable("Fatoora answered {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return $answer;
    }

    /**
     * @return array{request_id: string, token: string, secret: string}
     */
    private function csid(Response $response, string $what): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->serverError() || $response->status() === 429) {
            throw new FatooraUnavailable("Fatoora could not issue the {$what} ({$response->status()}); try again.");
        }

        if (! $response->successful() || ! is_string($body['binarySecurityToken'] ?? null) || ! is_string($body['secret'] ?? null)) {
            throw new FatooraRefused("Fatoora refused the {$what} ({$response->status()}): ".mb_substr($response->body(), 0, 500));
        }

        return [
            'request_id' => is_scalar($body['requestID'] ?? null) ? (string) $body['requestID'] : '',
            'token' => $body['binarySecurityToken'],
            'secret' => $body['secret'],
        ];
    }
}
