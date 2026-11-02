<?php

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Supplier\Enums\SupplierStatus;
use App\Modules\Supplier\Http\Requests\ListSuppliersRequest;
use App\Modules\Supplier\Http\Requests\SupplierRequest;
use App\Modules\Supplier\Http\Requests\SupplierReviewRequest;
use App\Modules\Supplier\Http\Resources\SupplierResource;
use App\Modules\Supplier\Models\Supplier;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class AdminSupplierController extends AdminController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    public function index(ListSuppliersRequest $request): JsonResponse
    {
        $page = Supplier::query()
            ->with(SupplierResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, SupplierResource::class);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            $supplier = new Supplier($request->validated());
            $supplier->created_by_admin_id = $this->admin($request)->id;
            $supplier->save();

            $this->audit->record($this->actor($request), 'supplier.create', $supplier, after: [
                ...$request->validated(),
                'status' => $supplier->status->value,
            ]);

            return $supplier;
        });

        return SupplierResource::make($supplier->load(SupplierResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(string $supplier): SupplierResource
    {
        return SupplierResource::make(
            Supplier::query()->with(SupplierResource::RELATIONS)->where('uuid', $supplier)->firstOrFail(),
        );
    }

    public function update(SupplierRequest $request, string $supplier): SupplierResource
    {
        $updated = DB::transaction(function () use ($request, $supplier): Supplier {
            $model = Supplier::query()->where('uuid', $supplier)->lockForUpdate()->firstOrFail();
            $model->fill([...SupplierRequest::CLEARED_IF_MISSING, ...$request->validated()]);
            $from = $model->status;

            // An approval belongs to the Alibaba store that was reviewed. Pointing
            // the record at another store (or country) sends it back for review.
            // A block stays: editing must never lift one.
            if ($from === SupplierStatus::Approved && $model->isDirty(['alibaba_store_url', 'country'])) {
                $model->status = SupplierStatus::Prospect;
                $model->reviewed_by_admin_id = null;
                $model->reviewed_at = null;
            }

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($model->status !== $from) {
                $this->history->record($model, $from, $model->status, $this->actor($request), 'store changed; needs a new review');
            }

            if ($after !== []) {
                $this->audit->record($this->actor($request), 'supplier.update', $model, $before, $after);
            }

            return $model;
        });

        return SupplierResource::make($updated->load(SupplierResource::RELATIONS));
    }

    /**
     * The approval decision, kept apart from editing so the people who add
     * suppliers and the people who approve them can be different. Records
     * who decided and when, even when the status stays the same.
     */
    public function review(SupplierReviewRequest $request, string $supplier): SupplierResource
    {
        $updated = DB::transaction(function () use ($request, $supplier): Supplier {
            $model = Supplier::query()->where('uuid', $supplier)->lockForUpdate()->firstOrFail();
            $from = $model->status;

            $model->fill($request->safe()->only(['quality_rating', 'review_notes']));
            $model->status = SupplierStatus::from($request->validated('status'));
            $model->reviewed_by_admin_id = $this->admin($request)->id;
            $model->reviewed_at = now();

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($model->status !== $from) {
                $this->history->record($model, $from, $model->status, $this->actor($request));
            }

            $this->audit->record($this->actor($request), 'supplier.review', $model, $before, $after);

            return $model;
        });

        return SupplierResource::make($updated->load(SupplierResource::RELATIONS));
    }
}
