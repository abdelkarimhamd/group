<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Modules\Catalog\Http\Requests\ProductImageRequest;
use App\Modules\Catalog\Http\Resources\AdminImageResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Audit\AuditLogger;
use App\Support\Files\StoredFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Product photos. Files go to the media disk (config groupbuy.media.disk):
 * local storage until the S3-compatible bucket is set up.
 */
final class AdminProductImageController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(ProductImageRequest $request, string $product): JsonResponse
    {
        $parent = Product::query()->where('uuid', $product)->firstOrFail();
        $variantId = null;

        if (($variantUuid = $request->validated('product_variant_id')) !== null) {
            $variantId = ProductVariant::query()
                ->where('product_id', $parent->id)
                ->where('uuid', $variantUuid)
                ->value('id') ?? throw ValidationException::withMessages([
                    'product_variant_id' => [__('validation.exists', ['attribute' => 'product_variant_id'])],
                ]);
        }

        $disk = (string) config('groupbuy.media.disk');
        $uuid = (string) Str::uuid7();
        $image = StoredFiles::storeThen($request->file('file'), "products/{$parent->uuid}", $disk,
            fn (string $path): ProductImage => DB::transaction(function () use ($request, $parent, $variantId, $disk, $uuid, $path): ProductImage {
                $image = new ProductImage($request->safe()->only(['alt_ar', 'alt_en', 'sort_order']));
                $image->uuid = $uuid;
                $image->product_id = $parent->id;
                $image->product_variant_id = $variantId;
                $image->disk = $disk;
                $image->path = $path;
                $image->save();

                $this->audit->record($this->actor($request), 'product_image.create', $image, after: [
                    'product_id' => $parent->uuid,
                    'path' => $path,
                ]);

                return $image;
            }),
            $uuid,
        );

        return AdminImageResource::make($image->load('variant'))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $image): Response
    {
        $deleted = DB::transaction(function () use ($request, $image): ProductImage {
            $model = ProductImage::query()->with('product')->where('uuid', $image)->lockForUpdate()->firstOrFail();
            $model->delete();

            $this->audit->record($this->actor($request), 'product_image.delete', $model, before: [
                'product_id' => $model->product->uuid,
                'path' => $model->path,
            ]);

            return $model;
        });

        // The row is gone for good; remove the file after the commit.
        StoredFiles::deleteQuietly($deleted->disk, $deleted->path);

        return response()->noContent();
    }
}
