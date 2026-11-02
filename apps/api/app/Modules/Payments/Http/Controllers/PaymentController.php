<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Http\Resources\ParticipationResource;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Http\Requests\SyncPaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Confirmations;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The customer's side of a card hold: checking it after the payment form,
 * and trying another card after a decline.
 */
final class PaymentController extends Controller
{
    public function __construct(private readonly Confirmations $confirmations) {}

    public function sync(SyncPaymentRequest $request, string $payment): ParticipationResource
    {
        $user = $this->customer($request);
        $model = PaymentAuthorization::query()
            ->where('uuid', $payment)
            ->whereIn('participation_id', CampaignParticipation::query()->select('id')->where('user_id', $user->id))
            ->firstOrFail();

        try {
            $this->confirmations->sync($model, $request->validated('provider_payment_id'), Actor::user($user->id));
        } catch (GatewayException) {
            throw ApiException::unavailable('payment_provider_unavailable');
        }

        return ParticipationResource::make(
            CampaignParticipation::query()->with(ParticipationResource::RELATIONS)->whereKey($model->participation_id)->firstOrFail(),
        );
    }

    /**
     * A new attempt for the same units and amount, once the last one failed
     * or was refused, and while the reservation lasts.
     */
    public function retry(Request $request, StatusHistory $history, string $participation): JsonResponse
    {
        $user = $this->customer($request);
        $id = CampaignParticipation::query()->where('uuid', $participation)->where('user_id', $user->id)->valueOrFail('id');

        $payment = DB::transaction(function () use ($id, $user, $history): PaymentAuthorization {
            $participation = CampaignParticipation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            // The hold went through: there is nothing to pay again.
            if (in_array($participation->status, [ParticipationStatus::Authorized, ParticipationStatus::Captured], true)) {
                throw new ApiException(409, 'already_paid');
            }

            if ($participation->status !== ParticipationStatus::PendingPayment || ! $participation->reservation_expires_at->greaterThan($now)) {
                throw new ApiException(409, 'reservation_expired');
            }

            $last = PaymentAuthorization::query()->attempts()->where('participation_id', $participation->id)->latest('id')->lockForUpdate()->first();

            // A declined card, or a hold refused for its amount or card type
            // (that one is being voided), lets the customer try again.
            if ($last !== null && $last->status !== PaymentStatus::Failed && $last->rejection_reason === null) {
                throw new ApiException(409, 'payment_in_progress');
            }

            $payment = PaymentAuthorization::create([
                'participation_id' => $participation->id,
                'authorized_amount_halalas' => $participation->hold_amount_halalas,
                'currency' => 'SAR',
                'status' => PaymentStatus::PendingAuthorization,
                'expires_at' => $now->addDays((int) config('groupbuy.payments.hold_lifetime_days')),
            ]);
            $history->record($payment, null, PaymentStatus::PendingAuthorization, Actor::user($user->id), 'retry');

            return $payment;
        });

        return PaymentResource::make($payment->load('participation.campaign'))->response()->setStatusCode(201);
    }

    private function customer(Request $request): User
    {
        return $request->user();
    }
}
