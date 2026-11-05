<?php

namespace Tests\Fakes;

use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentReports;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Gateway\ProviderSettlement;
use App\Modules\Payments\Gateway\ProviderSettlementLine;
use Carbon\CarbonImmutable;

/**
 * Moyasar's lists as a test sets them: payments, and settlements newest
 * first with their lines, served `perPage` at a time.
 */
final class ScriptedPaymentReports implements PaymentReports
{
    /** @var list<ProviderPayment> */
    public array $payments = [];

    /** @var list<ProviderSettlement> newest first */
    public array $settlements = [];

    /** @var array<string, list<ProviderSettlementLine>> by settlement ID */
    public array $lines = [];

    /** @var list<int> settlement pages read */
    public array $settlementPages = [];

    public bool $down = false;

    /** @var list<string> settlements whose lines cannot be read */
    public array $unreadable = [];

    public int $perPage = 2;

    public function payments(CarbonImmutable $from, CarbonImmutable $to, int $page = 1): array
    {
        return $this->page($this->payments, $page);
    }

    public function settlements(int $page = 1): array
    {
        $this->settlementPages[] = $page;

        return $this->page($this->settlements, $page);
    }

    public function settlementLines(string $settlementId, int $page = 1): array
    {
        if (in_array($settlementId, $this->unreadable, true)) {
            throw new GatewayException("The lines of {$settlementId} cannot be read.");
        }

        return $this->page($this->lines[$settlementId] ?? [], $page);
    }

    public function settle(string $id, string $settledAt, ProviderSettlementLine ...$lines): void
    {
        array_unshift($this->settlements, new ProviderSettlement($id, 'SAR', CarbonImmutable::parse($settledAt), ['id' => $id]));
        $this->lines[$id] = array_values($lines);
    }

    public static function line(string $type, ?string $paymentId, int $amount, int $fee, int $tax, ?int $paymentAmount = null): ProviderSettlementLine
    {
        return new ProviderSettlementLine($type, $paymentId, $amount, $fee, $tax, $paymentAmount, CarbonImmutable::now(), ['type' => $type, 'payment_id' => $paymentId]);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return array{items: list<T>, next_page: int|null}
     */
    private function page(array $items, int $page): array
    {
        if ($this->down) {
            throw new GatewayException('Moyasar is unreachable.');
        }

        $slice = array_slice($items, ($page - 1) * $this->perPage, $this->perPage);

        return ['items' => $slice, 'next_page' => count($items) > $page * $this->perPage ? $page + 1 : null];
    }
}
