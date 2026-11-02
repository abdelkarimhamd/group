<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Http\Requests\AdminListPaymentsRequest;
use App\Modules\Payments\Http\Resources\AdminPaymentResource;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Holds and charges in the admin portal, with every call made to Moyasar.
 */
final class AdminPaymentController extends AdminController
{
    public function index(AdminListPaymentsRequest $request): JsonResponse
    {
        $days = $request->validated('expiring_within_days');

        $query = PaymentAuthorization::query()
            ->with('participation.campaign')
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('campaign_id'), fn (Builder $query, string $campaign) => $query->whereIn(
                'participation_id',
                CampaignParticipation::query()->select('id')->whereIn('campaign_id', Campaign::query()->select('id')->where('uuid', $campaign)),
            ));

        if ($days !== null) {
            $query->where('status', PaymentStatus::Authorized)
                ->where('expires_at', '<=', CarbonImmutable::now()->addDays((int) $days))
                ->orderBy('expires_at')
                ->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }

        return CursorPage::response($query->cursorPaginate((int) $request->validated('per_page', 20)), AdminPaymentResource::class);
    }

    public function show(string $payment): AdminPaymentResource
    {
        return AdminPaymentResource::make(
            PaymentAuthorization::query()
                ->with(['participation.campaign', ...AdminPaymentResource::TRANSACTIONS])
                ->where('uuid', $payment)
                ->firstOrFail(),
        );
    }
}
