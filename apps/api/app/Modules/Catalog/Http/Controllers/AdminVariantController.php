<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\AdminController;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\VariantStatus;
use App\Modules\Catalog\Http\Requests\VariantRequest;
use App\Modules\Catalog\Http\Resources\AdminVariantResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use App\Support\Database\UniqueViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Variants are changed under a lock on their product, so an active product
 * can never lose its last active variant.
 */
final class AdminVariantController extends AdminController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    public function store(VariantRequest $request, string $product): JsonResponse
    {
        $variant = UniqueViolation::asValidationError(fn (): ProductVariant => DB::transaction(function () use ($request, $product): ProductVariant {
            $parent = Product::query()->where('uuid', $product)->lockForUpdate()->firstOrFail();

            $variant = $parent->variants()->make($request->safe()->except('status'));
            $variant->status = VariantStatus::from($request->validated('status', VariantStatus::Active->value));

            [, $after] = Changes::pending($variant);
            $variant->save();
            $this->audit->record($this->actor($request), 'product_variant.create', $variant, after: $after);

            return $variant;
        }), ['product_variants_sku_unique' => 'sku']);

        return AdminVariantResource::make($variant)->response()->setStatusCode(201);
    }

    public function update(VariantRequest $request, string $variant): AdminVariantResource
    {
        $updated = UniqueViolation::asValidationError(fn (): ProductVariant => DB::transaction(function () use ($request, $variant): ProductVariant {
            $productId = ProductVariant::query()->where('uuid', $variant)->valueOrFail('product_id');
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $model = ProductVariant::query()->where('uuid', $variant)->firstOrFail();
            $from = $model->status;

            $model->fill([...VariantRequest::CLEARED_IF_MISSING, ...$request->safe()->except('status')]);

            if ($request->has('status')) {
                $model->status = VariantStatus::from($request->validated('status'));
            }

            if ($model->status === VariantStatus::Archived && $from === VariantStatus::Active
                && $product->status === ProductStatus::Active && $this->isLastActiveVariant($model)) {
                throw ApiException::unprocessable('last_active_variant', 'status');
            }

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($model->status !== $from) {
                $this->history->record($model, $from, $model->status, $this->actor($request));
            }

            if ($after !== []) {
                $this->audit->record($this->actor($request), 'product_variant.update', $model, $before, $after);
            }

            return $model;
        }), ['product_variants_sku_unique' => 'sku']);

        return AdminVariantResource::make($updated);
    }

    private function isLastActiveVariant(ProductVariant $variant): bool
    {
        return ! ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->where('status', VariantStatus::Active)
            ->whereKeyNot($variant->id)
            ->exists();
    }
}
