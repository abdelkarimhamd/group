<?php

namespace App\Modules\Payments\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Gateway\ProviderPaymentNotFound;
use App\Modules\Payments\Jobs\VoidPayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Accepting card holds (spec, Payment holds: "Joining: authorization").
 *
 * The customer's return from the payment form, Moyasar's webhook and the
 * reservation expiry check all end here. Each fetches the payment from the
 * provider (never trusting what the client or the webhook body says),
 * outside any transaction, then applies it in one transaction in the lock
 * order campaign, participation, payment.
 *
 * A hold is accepted only if it is `authorized` (not captured at once) for
 * exactly the hold amount in SAR, by an allowed method. An accepted hold
 * counts the join's units (Joins::commitAuthorized). Any hold that is not
 * accepted, or that arrives for a join that can no longer count it, is
 * recorded and voided after commit, so no money is ever held untracked.
 *
 * The provider payment's metadata names our payment row. The payment form
 * can be submitted more than once, so a row may see several provider
 * payments: a row follows the newest one while it waits, and a hold that
 * arrives once the row is settled gets a row of its own, so payment
 * statuses only move forward. A second hold for a join that already counts
 * is voided as `duplicate_hold`.
 */
class Confirmations
{
    private const ALLOWED_SCHEMES = ['mada', 'visa', 'mastercard'];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly StatusHistory $history,
        private readonly Joins $joins,
        private readonly Holds $holds,
    ) {}

    /**
     * Check a payment after the customer returns from the payment form with
     * the provider's payment ID.
     *
     * @throws ApiException 422 provider_payment_mismatch when that provider payment is not for this payment
     */
    public function sync(PaymentAuthorization $payment, string $providerPaymentId, Actor $actor): PaymentAuthorization
    {
        $remote = $this->fetch($providerPaymentId);

        if ($remote->paymentId !== $payment->uuid) {
            throw ApiException::unprocessable('provider_payment_mismatch', 'provider_payment_id');
        }

        return $this->apply($payment, $remote, $actor);
    }

    /**
     * Apply a provider payment found from a webhook: the payment row it
     * belongs to comes from the provider's metadata. Returns null when the
     * provider payment names no payment of ours.
     */
    public function syncProviderPayment(string $providerPaymentId, Actor $actor): ?PaymentAuthorization
    {
        $remote = $this->fetch($providerPaymentId);
        $payment = $remote->paymentId === null ? null : PaymentAuthorization::query()->where('uuid', $remote->paymentId)->first();

        return $payment === null ? null : $this->apply($payment, $remote, $actor);
    }

    /**
     * Before reservations expire, ask the provider about the ones whose
     * payment it has already told us about but that are not settled yet, so
     * a hold that went through is counted instead of voided. Returns how
     * many were checked.
     */
    public function checkExpiringReservations(?CarbonImmutable $now = null, int $limit = 100): int
    {
        $now ??= CarbonImmutable::now();
        $due = PaymentAuthorization::query()
            ->where('status', PaymentStatus::PendingAuthorization)
            ->whereNotNull('provider_payment_id')
            ->whereIn('participation_id', CampaignParticipation::query()
                ->select('id')
                ->where('status', ParticipationStatus::PendingPayment)
                ->where('reservation_expires_at', '<=', $now))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $payment) {
            try {
                $this->sync($payment, (string) $payment->provider_payment_id, Actor::system());
            } catch (\Throwable $e) {
                // The reservation expires anyway; a hold found later is voided.
                Log::warning('payments.expiry_check.failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $due->count();
    }

    private function fetch(string $providerPaymentId): ProviderPayment
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        Log::info('payments.fetch.sending', ['provider_payment_id' => $providerPaymentId]);

        try {
            return $this->gateway->fetch($providerPaymentId);
        } catch (ProviderPaymentNotFound) {
            throw ApiException::unprocessable('provider_payment_mismatch', 'provider_payment_id');
        }
    }

    private function apply(PaymentAuthorization $payment, ProviderPayment $remote, Actor $actor): PaymentAuthorization
    {
        return DB::transaction(function () use ($payment, $remote, $actor): PaymentAuthorization {
            // Lock order: campaign, participation, payment.
            $campaignId = CampaignParticipation::query()->whereKey($payment->participation_id)->value('campaign_id');
            Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $participation = CampaignParticipation::query()->whereKey($payment->participation_id)->lockForUpdate()->firstOrFail();
            $payment = $this->rowFor(PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail(), $remote, $actor);

            if ($payment === null) {
                return PaymentAuthorization::query()->attempts()->where('participation_id', $participation->id)->latest('id')->firstOrFail();
            }

            $payment->provider_status = $remote->status;
            $payment->last_synced_at = CarbonImmutable::now();
            $payment->source_type = in_array($remote->sourceType, ['creditcard', 'applepay'], true) ? $remote->sourceType : $payment->source_type;
            $payment->scheme = in_array($remote->scheme, self::ALLOWED_SCHEMES, true) ? $remote->scheme : $payment->scheme;
            $payment->card_last4 = $remote->cardLast4 !== null && preg_match('/^\d{4}$/', $remote->cardLast4) === 1 ? $remote->cardLast4 : $payment->card_last4;

            if ($remote->status === 'failed' && $payment->status === PaymentStatus::PendingAuthorization) {
                $payment->status = PaymentStatus::Failed;
                $payment->rejection_reason = RejectionReason::Declined;
                $payment->save();
                $this->history->record($payment, PaymentStatus::PendingAuthorization, PaymentStatus::Failed, $actor, RejectionReason::Declined->value);

                return $payment;
            }

            if (! self::holdsMoney($remote) || $payment->status !== PaymentStatus::PendingAuthorization) {
                // Still processing, or already settled here: nothing changes but what we saw.
                $payment->save();

                return $payment;
            }

            $this->acceptOrVoid($participation, $payment, $remote, $actor);

            return $payment->refresh();
        });
    }

    /**
     * The payment row this provider payment belongs to, locked. Null when
     * the provider payment needs no row (one that holds no money, for a row
     * that is already settled).
     *
     * Payment statuses only move forward, so a settled row (declined,
     * released with its join, or holding a hold) never takes another
     * provider payment: a hold that arrives for one gets a row of its own,
     * which is then counted or voided like any other.
     */
    private function rowFor(PaymentAuthorization $payment, ProviderPayment $remote, Actor $actor): ?PaymentAuthorization
    {
        if ($payment->provider_payment_id === $remote->id) {
            return $payment;
        }

        $other = PaymentAuthorization::query()->where('provider_payment_id', $remote->id)->lockForUpdate()->first();

        if ($other !== null) {
            if ($other->participation_id !== $payment->participation_id) {
                throw ApiException::unprocessable('provider_payment_mismatch', 'provider_payment_id');
            }

            return $other;
        }

        // A row still waiting follows the newest payment made through its
        // form: the one it saw before held no money, or it would be settled.
        if ($payment->status === PaymentStatus::PendingAuthorization) {
            $payment->provider_payment_id = $remote->id;

            return $payment;
        }

        if (! self::holdsMoney($remote)) {
            return null;
        }

        $row = PaymentAuthorization::create([
            'participation_id' => $payment->participation_id,
            'provider_payment_id' => $remote->id,
            'authorized_amount_halalas' => $payment->authorized_amount_halalas,
            'currency' => 'SAR',
            'status' => PaymentStatus::PendingAuthorization,
            'expires_at' => CarbonImmutable::now()->addDays((int) config('groupbuy.payments.hold_lifetime_days')),
        ]);
        $this->history->record($row, null, PaymentStatus::PendingAuthorization, $actor, 'another_provider_payment', ['form_payment_id' => $payment->uuid]);

        return $row;
    }

    /**
     * Record a hold the provider reports, then count it or void it. A hold
     * that breaks the rules, or whose join can no longer count it (ended,
     * or already counted through another hold), is voided after commit.
     */
    private function acceptOrVoid(CampaignParticipation $participation, PaymentAuthorization $payment, ProviderPayment $remote, Actor $actor): void
    {
        $problem = $this->problemWith($payment, $remote)
            ?? ($participation->status === ParticipationStatus::PendingPayment ? null : self::reasonFor($participation->status));

        $payment->status = PaymentStatus::Authorized;
        $payment->authorized_at = CarbonImmutable::now();
        $payment->rejection_reason = $problem;
        $payment->save();
        $this->history->record($payment, PaymentStatus::PendingAuthorization, PaymentStatus::Authorized, $actor, $problem?->value);

        if ($problem !== null) {
            VoidPayment::dispatch($payment->id, $actor->type->value, $actor->id)->afterCommit();

            return;
        }

        // A campaign past settling its joins releases the units and this hold.
        if (! $this->joins->commitAuthorized($participation, $actor) && $payment->refresh()->rejection_reason === null) {
            $this->holds->release($payment, self::reasonFor($participation->refresh()->status), $actor);
        }
    }

    /**
     * Why a hold cannot be accepted, or null when it can.
     */
    private function problemWith(PaymentAuthorization $payment, ProviderPayment $remote): ?RejectionReason
    {
        return match (true) {
            $remote->status !== 'authorized' => RejectionReason::NotAuthorizeOnly,
            $remote->currency !== 'SAR' => RejectionReason::CurrencyMismatch,
            $remote->amountHalalas !== $payment->authorized_amount_halalas => RejectionReason::AmountMismatch,
            ! in_array($remote->sourceType, ['creditcard', 'applepay'], true),
            ! in_array($remote->scheme, self::ALLOWED_SCHEMES, true) => RejectionReason::MethodNotAllowed,
            default => null,
        };
    }

    private static function holdsMoney(ProviderPayment $remote): bool
    {
        return in_array($remote->status, ['authorized', 'paid', 'captured'], true);
    }

    private static function reasonFor(ParticipationStatus $status): RejectionReason
    {
        return match ($status) {
            ParticipationStatus::ReservationExpired, ParticipationStatus::PaymentFailed => RejectionReason::ReservationExpired,
            ParticipationStatus::Cancelled => RejectionReason::ParticipationCancelled,
            ParticipationStatus::Released => RejectionReason::CampaignClosed,
            default => RejectionReason::DuplicateHold,
        };
    }
}
