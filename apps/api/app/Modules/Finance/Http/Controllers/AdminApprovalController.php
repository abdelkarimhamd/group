<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Http\Requests\ReasonRequest;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Http\Requests\AdminListApprovalRequestsRequest;
use App\Modules\Finance\Http\Resources\ApprovalRequestResource;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Services\Decisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The second-admin queue (spec, E15): refunds and campaign cancellations
 * wait here until an admin other than the requester decides them.
 */
final class AdminApprovalController extends AdminController
{
    public function index(AdminListApprovalRequestsRequest $request): JsonResponse
    {
        $page = ApprovalRequest::query()
            ->with(ApprovalRequestResource::RELATIONS)
            ->where('status', $request->validated('status', ApprovalStatus::Pending->value))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, ApprovalRequestResource::class);
    }

    /**
     * Approve, then carry out the action after commit.
     */
    public function approve(Request $request, string $approvalRequest, Decisions $decisions): ApprovalRequestResource
    {
        $decided = $decisions->approve($approvalRequest, $this->admin($request));

        return ApprovalRequestResource::make($decided->load(ApprovalRequestResource::RELATIONS));
    }

    public function reject(ReasonRequest $request, string $approvalRequest, Decisions $decisions): ApprovalRequestResource
    {
        $decided = $decisions->reject($approvalRequest, $request->validated('reason'), $this->admin($request));

        return ApprovalRequestResource::make($decided->load(ApprovalRequestResource::RELATIONS));
    }
}
