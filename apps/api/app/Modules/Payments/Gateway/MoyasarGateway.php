<?php

namespace App\Modules\Payments\Gateway;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Moyasar's REST API (https://api.moyasar.com/v1), with the secret key as
 * the Basic-auth user. Callers write and commit their attempt row and log
 * it before calling (see PaymentGateway); this class only talks HTTP and
 * sorts the answer into three kinds:
 *
 *  - a definite yes: 2xx with the payment in the state the call asked for;
 *  - a definite no: 400, 404 or 422 about this payment (already captured,
 *    void window passed, amount too large), returned as a refusal;
 *  - anything else is an unknown outcome (GatewayException): no answer,
 *    a timeout, 5xx, 429, or our own set-up refused (401 bad key, 403 IP
 *    not allowed, 405 account not live). Callers then ask Moyasar with
 *    fetch() before trying again, so nothing is sent twice blind.
 *
 * Moyasar documents no idempotency for capture, void or refund (only
 * given_id on payment creation), so the key is sent as an Idempotency-Key
 * header in case, but the fetch-first retries are what prevent doubles.
 */
final class MoyasarGateway implements PaymentGateway, PaymentReports
{
    /** Refusals that are about our set-up, not the payment: retrying after a fix can work. */
    private const SETUP_ERRORS = [401, 403, 405];

    /** Moyasar's names for the card networks, as ours (Confirmations, the scheme CHECK). */
    private const SCHEMES = ['master' => 'mastercard'];

    /**
     * Production takes live keys only, anywhere else test keys only, and the
     * two keys always match: the payment form makes its payment with the
     * publishable key, and a payment made in the other mode is invisible to
     * the secret key (every join would fail, or real cards would be held
     * where nothing can confirm or release them).
     */
    public function __construct(
        private readonly string $secretKey,
        string $publishableKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        bool $production,
    ) {
        if ($secretKey === '') {
            throw new LogicException('Set MOYASAR_SECRET_KEY to use the Moyasar gateway.');
        }

        $mode = $production ? 'live' : 'test';

        if (! str_starts_with($secretKey, "sk_{$mode}_") || ! str_starts_with($publishableKey, "pk_{$mode}_")) {
            throw new LogicException($production
                ? 'Production needs live Moyasar keys: MOYASAR_SECRET_KEY sk_live_... and MOYASAR_PUBLISHABLE_KEY pk_live_....'
                : 'Outside production use test Moyasar keys: MOYASAR_SECRET_KEY sk_test_... and MOYASAR_PUBLISHABLE_KEY pk_test_....');
        }
    }

    public function fetch(string $providerPaymentId): ProviderPayment
    {
        $response = $this->send('get', 'payments/'.rawurlencode($providerPaymentId), null, null);

        if ($response->status() === 404) {
            throw new ProviderPaymentNotFound("Moyasar has no payment [{$providerPaymentId}].");
        }

        if (! $response->successful()) {
            throw new GatewayException("Moyasar answered {$response->status()} to fetching a payment.");
        }

        return self::payment(self::body($response));
    }

    public function void(string $providerPaymentId, string $idempotencyKey): GatewayResult
    {
        return $this->operate($providerPaymentId, 'void', null, $idempotencyKey, ['voided']);
    }

    public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        return $this->operate($providerPaymentId, 'capture', $amountHalalas, $idempotencyKey, ['captured']);
    }

    public function refund(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
    {
        return $this->operate($providerPaymentId, 'refund', $amountHalalas, $idempotencyKey, ['refunded']);
    }

    public function payments(CarbonImmutable $from, CarbonImmutable $to, int $page = 1): array
    {
        // created[gt] and created[lt] are both inclusive ("on or after", "on or before").
        $page = $this->page('payments', [
            'page' => $page,
            'created[gt]' => $from->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'created[lt]' => $to->utc()->subSecond()->format('Y-m-d\\TH:i:s\\Z'),
        ], ['payments']);

        return ['items' => array_map(self::payment(...), $page['items']), 'next_page' => $page['next_page']];
    }

    public function settlements(int $page = 1): array
    {
        $page = $this->page('settlements', ['page' => $page], ['settlements']);

        return ['items' => array_map(self::settlement(...), $page['items']), 'next_page' => $page['next_page']];
    }

    public function settlementLines(string $settlementId, int $page = 1): array
    {
        $page = $this->page('settlements/'.rawurlencode($settlementId).'/lines', ['page' => $page], ['lines', 'settlement_lines']);

        return ['items' => array_map(self::settlementLine(...), $page['items']), 'next_page' => $page['next_page']];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function settlement(array $body): ProviderSettlement
    {
        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new GatewayException('Moyasar sent a settlement without an id.');
        }

        $currency = is_string($body['currency'] ?? null) ? strtoupper($body['currency']) : 'SAR';

        return new ProviderSettlement($id, $currency, self::time($body['settlement_date'] ?? $body['settled_at'] ?? $body['created_at'] ?? null), $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function settlementLine(array $body): ProviderSettlementLine
    {
        if (! is_string($body['type'] ?? null) || ! is_int($body['amount'] ?? null)) {
            throw new GatewayException('Moyasar sent a settlement line without a type or amount.');
        }

        $paymentId = $body['payment_id'] ?? null;

        return new ProviderSettlementLine(
            type: mb_substr($body['type'], 0, 32),
            paymentId: is_string($paymentId) && $paymentId !== '' ? $paymentId : null,
            amountHalalas: $body['amount'],
            feeHalalas: is_int($body['fee'] ?? null) ? $body['fee'] : 0,
            taxHalalas: is_int($body['tax'] ?? null) ? $body['tax'] : 0,
            paymentAmountHalalas: is_int($body['payment_amount'] ?? null) ? $body['payment_amount'] : null,
            transactedAt: self::time($body['transacted_at'] ?? null),
            raw: $body,
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One page of a Moyasar list: the objects under the first key found,
     * and the next page from meta.next_page.
     *
     * @param  array<string, int|string>  $query
     * @param  list<string>  $keys
     * @return array{items: list<array<string, mixed>>, next_page: int|null}
     */
    private function page(string $path, array $query, array $keys): array
    {
        $response = $this->send('get', $path, null, null, $query);

        if (! $response->successful()) {
            $why = in_array($response->status(), self::SETUP_ERRORS, true) ? ' (check the Moyasar key, IP whitelist and account)' : '';

            throw new GatewayException("Moyasar answered {$response->status()} to listing {$path}{$why}.");
        }

        $body = self::body($response);
        $items = null;

        foreach ($keys as $key) {
            if (is_array($body[$key] ?? null)) {
                $items = $body[$key];
                break;
            }
        }

        if ($items === null || ! array_is_list($items)) {
            throw new GatewayException("Moyasar sent no list for {$path}.");
        }

        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $next = $meta['next_page'] ?? null;

        return [
            'items' => array_values(array_filter($items, 'is_array')),
            'next_page' => is_int($next) && $next > (int) ($query['page'] ?? 1) ? $next : null,
        ];
    }

    /**
     * Turn a Moyasar payment object into ours. Amounts are integers in
     * halalas; our payment's UUID travels in metadata.payment_id (Checkout).
     *
     * @param  array<string, mixed>  $body
     */
    public static function payment(array $body): ProviderPayment
    {
        $id = $body['id'] ?? null;
        $status = $body['status'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($status) || ! is_int($body['amount'] ?? null)) {
            throw new GatewayException('Moyasar sent a payment without an id, status or amount.');
        }

        $source = is_array($body['source'] ?? null) ? $body['source'] : [];
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
        // The number is masked (first six and last four); keep only the last four digits.
        $digits = preg_replace('/\D/', '', is_string($source['number'] ?? null) ? $source['number'] : '');
        $paymentId = $metadata['payment_id'] ?? null;

        return new ProviderPayment(
            id: $id,
            status: $status,
            amountHalalas: $body['amount'],
            currency: is_string($body['currency'] ?? null) ? strtoupper($body['currency']) : '',
            sourceType: is_string($source['type'] ?? null) ? $source['type'] : null,
            scheme: is_string($source['company'] ?? null) ? (self::SCHEMES[strtolower($source['company'])] ?? strtolower($source['company'])) : null,
            cardLast4: strlen((string) $digits) >= 4 ? substr((string) $digits, -4) : null,
            capturedHalalas: is_int($body['captured'] ?? null) ? $body['captured'] : 0,
            paymentId: is_string($paymentId) && $paymentId !== '' ? strtolower($paymentId) : null,
            raw: $body,
            refundedHalalas: is_int($body['refunded'] ?? null) ? $body['refunded'] : 0,
        );
    }

    /**
     * @param  list<string>  $expected  The payment statuses that mean it worked.
     */
    private function operate(string $providerPaymentId, string $operation, ?int $amountHalalas, string $idempotencyKey, array $expected): GatewayResult
    {
        $response = $this->send('post', 'payments/'.rawurlencode($providerPaymentId).'/'.$operation, $amountHalalas === null ? null : ['amount' => $amountHalalas], $idempotencyKey);
        $body = self::body($response);

        if ($response->successful()) {
            $payment = self::payment($body);

            if (! in_array($payment->status, $expected, true)) {
                // A 2xx that did not do what we asked: ask again before deciding.
                throw new GatewayException("Moyasar answered {$operation} with the payment {$payment->status}.");
            }

            return GatewayResult::succeeded($payment->id, $body);
        }

        if (in_array($response->status(), [400, 404, 422], true)) {
            $code = is_string($body['type'] ?? null) && $body['type'] !== '' ? $body['type'] : 'http_'.$response->status();
            $message = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : "Moyasar refused the {$operation}.";
            $errors = is_array($body['errors'] ?? null) ? json_encode($body['errors'], JSON_UNESCAPED_UNICODE) : null;

            return GatewayResult::refused(mb_substr($code, 0, 64), $errors === null || $errors === false ? $message : "{$message} {$errors}", $body);
        }

        $why = in_array($response->status(), self::SETUP_ERRORS, true) ? ' (check the Moyasar key, IP whitelist and account)' : '';

        throw new GatewayException("Moyasar answered {$response->status()} to {$operation}{$why}.");
    }

    /**
     * @param  array<string, int>|null  $json
     * @param  array<string, int|string>  $query
     */
    private function send(string $method, string $path, ?array $json, ?string $idempotencyKey, array $query = []): Response
    {
        $request = $this->client();

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = $method === 'get' ? $request->get($path, $query) : $request->post($path, $json ?? []);
        } catch (ConnectionException $e) {
            throw new GatewayException('Moyasar could not be reached: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new GatewayException('The call to Moyasar failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new GatewayException("Moyasar answered {$response->status()}; try again later.");
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
