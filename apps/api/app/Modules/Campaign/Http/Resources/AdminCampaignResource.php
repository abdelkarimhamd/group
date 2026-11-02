<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Http\Resources\ActorJson;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Catalog\Http\Resources\AdminVariantResource;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Pricing\Tier;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminCampaign` schema. Load RELATIONS first.
 *
 * @mixin Campaign
 */
class AdminCampaignResource extends JsonResource
{
    public const RELATIONS = ['product', 'quotation.supplier', 'variants', 'currentTiers', 'creator', 'approver'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quotation = $this->quotation;
        $awaitingSupplier = $this->status === CampaignStatus::Closing && $this->closing_step === ClosingStep::AwaitingSupplier;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status->value,
            'product' => [
                'id' => $this->product->uuid,
                'name_ar' => $this->product->name_ar,
                'name_en' => $this->product->name_en,
            ],
            'supplier' => [
                'id' => $quotation->supplier->uuid,
                'name' => $quotation->supplier->name,
                'moq' => $quotation->moq,
            ],
            'supplier_quotation_id' => $quotation->uuid,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'variants' => AdminVariantResource::collection($this->variants)->resolve($request),
            'min_target_qty' => $this->min_target_qty,
            'max_qty' => $this->max_qty,
            'committed_qty' => $this->committed_qty,
            'reserved_qty' => $this->reserved_qty,
            'per_customer_max_qty' => $this->per_customer_max_qty,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'deadline_at' => $this->deadline_at?->toIso8601ZuluString(),
            'original_deadline_at' => $this->original_deadline_at?->toIso8601ZuluString(),
            'extended_at' => $this->extended_at?->toIso8601ZuluString(),
            'extension_reason' => $this->extension_reason,
            'deal_confirmed_at' => $this->deal_confirmed_at?->toIso8601ZuluString(),
            'close_reason' => $this->close_reason?->value,
            'closing_step' => $this->closing_step?->value,
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'final_qty' => $this->final_qty,
            'final_unit_price' => $this->final_unit_price_halalas === null ? null : Money::sar($this->final_unit_price_halalas),
            'capture_by' => $this->capture_by?->toIso8601ZuluString(),
            'supplier_decision_due_at' => $awaitingSupplier ? Closing::supplierDecisionDueAt($this->resource)?->toIso8601ZuluString() : null,
            'captured_qty' => $this->captured_qty,
            'end_reason' => $this->end_reason,
            'tier_version' => $this->current_tier_version,
            'tiers' => array_map(fn (Tier $tier) => [
                'min_qty' => $tier->minQty,
                'max_qty' => $tier->maxQty,
                'unit_price' => $tier->unitPrice(),
            ], $this->resource->tierSet()?->tiers ?? []),
            'landed_cost_per_unit' => Money::sar($this->landed_cost_per_unit_halalas ?? $quotation->landed_cost_per_unit_halalas),
            'delivery_mode' => $this->delivery_mode->value,
            'created_by' => ActorJson::admin($this->creator),
            'approved_by' => ActorJson::admin($this->approver),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'lock_version' => $this->lock_version,
            'available_actions' => $this->availableActions($request->user()),
        ];
    }

    /**
     * What this admin may do now, given the status and their permissions.
     * The server still checks each action when it is taken.
     *
     * @return list<string>
     */
    private function availableActions(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        $status = $this->status;
        $step = $this->closing_step;
        // Before captures a cancel takes effect at once; after, it asks finance to approve (202).
        $cancellable = in_array($status, [CampaignStatus::Draft, CampaignStatus::PendingApproval, CampaignStatus::Scheduled, CampaignStatus::Active, CampaignStatus::Purchasing], true)
            || ($status === CampaignStatus::Closing && $step !== ClosingStep::Done);
        $actions = [
            'edit' => $status === CampaignStatus::Draft && $admin->can('campaigns.edit'),
            'edit_tiers' => $status === CampaignStatus::Draft && $admin->can('pricing.edit'),
            'submit' => $status === CampaignStatus::Draft && $admin->can('campaigns.edit'),
            'approve' => $status === CampaignStatus::PendingApproval && $admin->can('campaigns.approve'),
            'reject' => $status === CampaignStatus::PendingApproval && $admin->can('campaigns.approve'),
            'extend' => $status === CampaignStatus::Active && $this->extended_at === null
                && $this->deal_confirmed_at === null && $admin->can('campaigns.extend'),
            'cancel' => $cancellable && $admin->can('campaigns.cancel'),
            'record_supplier_decision' => $status === CampaignStatus::Closing && $step === ClosingStep::AwaitingSupplier && $admin->can('campaigns.close'),
            'decide_below_target' => $status === CampaignStatus::Closing && $step === ClosingStep::NeedsAdmin && $admin->can('campaigns.close'),
        ];

        return array_keys(array_filter($actions));
    }
}
