<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\VariantStatus;
use App\Modules\Catalog\Http\Requests\ListProductsRequest;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Resources\AdminProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class AdminProductController extends AdminController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $page = Product::query()
            ->with(AdminProductResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('q'), function (Builder $query, string $search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn (Builder $match) => $match
                    ->where('name_ar', 'ilike', $like)
                    ->orWhere('name_en', 'ilike', $like)
                    ->orWhere('alibaba_url', 'ilike', $like)
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', $like)));
            })
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, AdminProductResource::class);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = new Product($request->safe()->except(['category_id', 'variants']));
            $product->category_id = $this->categoryId($request->validated('category_id'));
            $product->created_by_admin_id = $this->admin($request)->id;
            $product->save();

            foreach ($request->validated('variants', []) as $input) {
                $product->variants()->create($input);
            }

            $this->audit->record($this->actor($request), 'product.create', $product, after: [
                ...$product->only(['name_ar', 'name_en', 'alibaba_url', 'hs_code']),
                'status' => $product->status,
                'variants' => array_column($request->validated('variants', []), 'sku'),
            ]);

            return $product;
        });

        return AdminProductResource::make($product->load(AdminProductResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(string $product): AdminProductResource
    {
        return AdminProductResource::make(
            Product::query()->with(AdminProductResource::RELATIONS)->where('uuid', $product)->firstOrFail(),
        );
    }

    public function update(ProductRequest $request, string $product): AdminProductResource
    {
        $updated = DB::transaction(function () use ($request, $product): Product {
            $model = Product::query()->where('uuid', $product)->lockForUpdate()->firstOrFail();
            $from = $model->status;

            $model->fill([...ProductRequest::CLEARED_IF_MISSING, ...$request->safe()->except(['category_id', 'status'])]);
            $model->category_id = $this->categoryId($request->validated('category_id'));

            if ($request->has('status')) {
                $model->status = ProductStatus::from($request->validated('status'));
            }

            if ($model->status === ProductStatus::Active && $from !== ProductStatus::Active && ! $this->hasActiveVariant($model)) {
                throw ApiException::unprocessable('product_needs_variant', 'status');
            }

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($model->status !== $from) {
                $this->history->record($model, $from, $model->status, $this->actor($request));
            }

            if ($after !== []) {
                $this->audit->record($this->actor($request), 'product.update', $model, $before, $after);
            }

            return $model;
        });

        return AdminProductResource::make($updated->load(AdminProductResource::RELATIONS));
    }

    private function categoryId(string $uuid): int
    {
        return Category::query()->where('uuid', $uuid)->valueOrFail('id');
    }

    private function hasActiveVariant(Product $product): bool
    {
        return ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('status', VariantStatus::Active)
            ->exists();
    }
}
