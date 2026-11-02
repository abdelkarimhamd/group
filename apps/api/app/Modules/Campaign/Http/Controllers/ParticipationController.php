<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Http\Requests\JoinCampaignRequest;
use App\Modules\Campaign\Http\Requests\ListMyParticipationsRequest;
use App\Modules\Campaign\Http\Resources\ParticipationResource;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParticipationController extends Controller
{
    public function __construct(private readonly Joins $joins) {}

    /**
     * Join a campaign. A retry with the same client_request_id answers 201
     * with the original participation.
     */
    public function store(JoinCampaignRequest $request, string $campaign): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = Campaign::query()->where('uuid', $campaign)->whereNotNull('approved_at')->firstOrFail();

        $participation = $this->joins->join($user, $model, $request->validated());

        return ParticipationResource::make($participation->load(ParticipationResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * My joins, newest first, including those whose payment failed.
     */
    public function index(ListMyParticipationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = CampaignParticipation::query()
            ->where('user_id', $user->id)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->with(ParticipationResource::RELATIONS)
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, ParticipationResource::class);
    }

    public function show(Request $request, string $participation): ParticipationResource
    {
        /** @var User $user */
        $user = $request->user();

        return ParticipationResource::make(
            CampaignParticipation::query()
                ->where('user_id', $user->id)
                ->where('uuid', $participation)
                ->with(ParticipationResource::RELATIONS)
                ->firstOrFail(),
        );
    }

    /**
     * Cancel a join while `can_cancel` is true; the hold is voided after commit.
     */
    public function cancel(Request $request, string $participation): ParticipationResource
    {
        /** @var User $user */
        $user = $request->user();

        return ParticipationResource::make(
            $this->joins->cancelByCustomer($user, $participation)->fresh(ParticipationResource::RELATIONS),
        );
    }
}
