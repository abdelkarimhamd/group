<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaign\Http\Requests\JoinCampaignRequest;
use App\Modules\Campaign\Http\Resources\ParticipationResource;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;

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
}
