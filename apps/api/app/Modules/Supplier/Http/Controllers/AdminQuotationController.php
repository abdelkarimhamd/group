<?php

namespace App\Modules\Supplier\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Supplier\Enums\QuotationStatus;
use App\Modules\Supplier\Enums\SupplierStatus;
use App\Modules\Supplier\Http\Requests\ListQuotationsRequest;
use App\Modules\Supplier\Http\Requests\QuotationAttachmentRequest;
use App\Modules\Supplier\Http\Requests\QuotationRequest;
use App\Modules\Supplier\Http\Resources\QuotationResource;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Modules\Supplier\Services\QuotationUsage;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Supplier quotes and their landed cost. The server works out the landed cost
 * per unit from the breakdown. A quote a campaign past draft relies on is
 * frozen (409 quotation_frozen).
 */
final class AdminQuotationController extends AdminController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
        private readonly QuotationUsage $usage,
    ) {}

    public function index(ListQuotationsRequest $request): JsonResponse
    {
        $page = SupplierQuotation::query()
            ->with(QuotationResource::RELATIONS)
            ->when($request->validated('supplier_id'), fn (Builder $query, string $uuid) => $query
                ->whereIn('supplier_id', Supplier::query()->select('id')->where('uuid', $uuid)))
            ->when($request->validated('product_id'), fn (Builder $query, string $uuid) => $query
                ->whereIn('product_id', Product::query()->select('id')->where('uuid', $uuid)))
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, QuotationResource::class);
    }

    public function store(QuotationRequest $request): JsonResponse
    {
        $quotation = DB::transaction(function () use ($request): SupplierQuotation {
            $quotation = new SupplierQuotation;
            $this->fill($quotation, $request);
            $quotation->created_by_admin_id = $this->admin($request)->id;
            $quotation->save();

            $this->audit->record($this->actor($request), 'quotation.create', $quotation, after: [
                ...$request->safe()->except('landed_cost_breakdown'),
                'landed_cost_breakdown' => $quotation->landed_cost_breakdown,
                'landed_cost_per_unit_halalas' => $quotation->landed_cost_per_unit_halalas,
                'status' => $quotation->status->value,
            ]);

            return $quotation;
        });

        return QuotationResource::make($quotation->load(QuotationResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(string $quotation): QuotationResource
    {
        return QuotationResource::make(
            SupplierQuotation::query()->with(QuotationResource::RELATIONS)->where('uuid', $quotation)->firstOrFail(),
        );
    }

    public function update(QuotationRequest $request, string $quotation): QuotationResource
    {
        $updated = DB::transaction(function () use ($request, $quotation): SupplierQuotation {
            $model = $this->lockEditable($quotation);
            $from = $model->status;

            $this->fill($model, $request);

            if (($status = $request->validated('status')) !== null) {
                $model->status = QuotationStatus::from($status);
            }

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($model->status !== $from) {
                $this->history->record($model, $from, $model->status, $this->actor($request));
            }

            if ($after !== []) {
                $this->audit->record($this->actor($request), 'quotation.update', $model, $before, $after);
            }

            return $model;
        });

        return QuotationResource::make($updated->load(QuotationResource::RELATIONS));
    }

    /**
     * Stores the quote PDF or screenshot on the private documents disk and
     * replaces any earlier one.
     */
    public function attach(QuotationAttachmentRequest $request, string $quotation): QuotationResource
    {
        $parent = SupplierQuotation::query()->where('uuid', $quotation)->firstOrFail();
        $disk = (string) config('groupbuy.media.documents_disk');
        $file = $request->file('file');
        $path = $file->storeAs("quotations/{$parent->uuid}", Str::uuid7().'.'.$file->extension(), $disk);

        try {
            [$updated, $previous] = DB::transaction(function () use ($request, $quotation, $disk, $path): array {
                $model = $this->lockEditable($quotation);
                $previous = $model->attachment_path === null ? null : [$model->attachment_disk, $model->attachment_path];

                $model->attachment_disk = $disk;
                $model->attachment_path = $path;
                $model->save();

                $this->audit->record($this->actor($request), 'quotation.attach', $model,
                    before: $previous === null ? null : ['attachment_path' => $previous[1]],
                    after: ['attachment_path' => $path],
                );

                return [$model, $previous];
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        // The old file is no longer referenced; remove it after the commit.
        if ($previous !== null) {
            Storage::disk($previous[0])->delete($previous[1]);
        }

        return QuotationResource::make($updated->load(QuotationResource::RELATIONS));
    }

    private function lockEditable(string $uuid): SupplierQuotation
    {
        $model = SupplierQuotation::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

        if ($this->usage->isFrozen($model)) {
            throw new ApiException(409, 'quotation_frozen');
        }

        return $model;
    }

    /**
     * Copies the request onto the quotation and enforces the rules that need
     * the database: no new or accepted quote from a blocked supplier, and no
     * accepted quote past its validity date (Riyadh time).
     */
    private function fill(SupplierQuotation $quotation, QuotationRequest $request): void
    {
        $supplier = Supplier::query()->where('uuid', $request->validated('supplier_id'))->firstOrFail();
        // On create `status` has no rule, so a sent value is ignored rather than trusted.
        $status = ($value = $request->validated('status')) === null ? $quotation->status : QuotationStatus::from($value);
        $accepting = $status === QuotationStatus::Accepted;

        if ($supplier->status === SupplierStatus::Blocked && (! $quotation->exists || $accepting)) {
            throw ApiException::unprocessable('supplier_blocked', 'supplier_id');
        }

        $quotation->fill($request->safe()->only([
            'moq', 'unit_cost_minor', 'currency', 'shipping_terms', 'lead_time_days', 'valid_until', 'fx_rate_to_sar', 'landed_cost_basis_qty',
        ]));
        $quotation->supplier_id = $supplier->id;
        $quotation->product_id = Product::query()->where('uuid', $request->validated('product_id'))->valueOrFail('id');
        $quotation->product_variant_id = ($variant = $request->validated('product_variant_id')) === null
            ? null
            : ProductVariant::query()->where('uuid', $variant)->valueOrFail('id');
        $quotation->setLandedCost($request->breakdown());

        $today = CarbonImmutable::now((string) config('groupbuy.display_timezone'));

        if ($accepting && $quotation->isExpiredOn($today)) {
            throw ApiException::unprocessable('quotation_expired', 'valid_until');
        }
    }
}
