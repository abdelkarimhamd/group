<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Http\Requests\AdminListCampaignsRequest;
use App\Modules\Campaign\Http\Requests\ApproveCampaignRequest;
use App\Modules\Campaign\Http\Requests\CampaignRequest;
use App\Modules\Campaign\Http\Requests\ExtendCampaignRequest;
use App\Modules\Campaign\Http\Requests\ListCampaignEventsRequest;
use App\Modules\Campaign\Http\Requests\ListParticipationsRequest;
use App\Modules\Campaign\Http\Requests\ReasonRequest;
use App\Modules\Campaign\Http\Requests\TierSetRequest;
use App\Modules\Campaign\Http\Resources\AdminCampaignResource;
use App\Modules\Campaign\Http\Resources\AdminParticipationResource;
use App\Modules\Campaign\Http\Resources\CampaignEventResource;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Pricing\MarginCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campaigns in the admin portal. Each action is a guarded status change in
 * CampaignLifecycle; every one is audited.
 */
final class AdminCampaignController extends AdminController
{
    public function __construct(private readonly CampaignLifecycle $lifecycle) {}

    public function index(AdminListCampaignsRequest $request): JsonResponse
    {
        $page = Campaign::query()
            ->with(AdminCampaignResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(in_array($request->validated('needs_attention'), ['true', '1'], true), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('status', CampaignStatus::PendingApproval)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', CampaignStatus::Closing)
                        ->whereIn('closing_step', [ClosingStep::AwaitingSupplier, ClosingStep::NeedsAdmin])),
            ))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, AdminCampaignResource::class);
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        $campaign = $this->lifecycle->create($request->validated(), $this->admin($request));

        return $this->respond($campaign)->response()->setStatusCode(201);
    }

    public function show(string $campaign): AdminCampaignResource
    {
        return $this->respond(Campaign::query()->where('uuid', $campaign)->firstOrFail());
    }

    public function update(CampaignRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->update($campaign, $request->validated(), $this->admin($request)));
    }

    public function replaceTiers(TierSetRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->replaceTiers(
            $campaign,
            (int) $request->validated('lock_version'),
            $request->validated('tiers'),
            $request->validated('reason'),
            $this->admin($request),
        ));
    }

    public function margin(MarginCalculator $margins, string $campaign): JsonResponse
    {
        return response()->json(['data' => $margins->report(Campaign::query()->where('uuid', $campaign)->firstOrFail())]);
    }

    public function submit(Request $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->submit($campaign, $this->admin($request)));
    }

    public function approve(ApproveCampaignRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->approve($campaign, $request->validated('override_reason'), $this->admin($request)));
    }

    public function reject(ReasonRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->reject($campaign, $request->validated('reason'), $this->admin($request)));
    }

    public function extensionWindow(string $campaign): JsonResponse
    {
        $model = Campaign::query()->where('uuid', $campaign)->firstOrFail();

        return response()->json(['data' => CampaignLifecycle::windowJson($this->lifecycle->extensionWindow($model))]);
    }

    public function extend(ExtendCampaignRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->extend(
            $campaign,
            CarbonImmutable::parse($request->validated('new_deadline_at'))->utc(),
            $request->validated('reason'),
            $this->admin($request),
        ));
    }

    public function cancel(ReasonRequest $request, string $campaign): AdminCampaignResource
    {
        return $this->respond($this->lifecycle->cancel($campaign, $request->validated('reason'), $this->admin($request)));
    }

    public function participations(ListParticipationsRequest $request, string $campaign): JsonResponse
    {
        $model = Campaign::query()->where('uuid', $campaign)->firstOrFail();

        $page = $model->participations()
            ->with(AdminParticipationResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, AdminParticipationResource::class);
    }

    public function events(ListCampaignEventsRequest $request, string $campaign): JsonResponse
    {
        $model = Campaign::query()->where('uuid', $campaign)->firstOrFail();

        $page = $model->events()
            ->with(CampaignEventResource::RELATIONS)
            ->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, CampaignEventResource::class);
    }

    private function respond(Campaign $campaign): AdminCampaignResource
    {
        return AdminCampaignResource::make($campaign->fresh(AdminCampaignResource::RELATIONS));
    }
}
