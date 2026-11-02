<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\AdminController;
use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Http\Resources\AdminCategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class AdminCategoryController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection(
            Category::query()->with('parent')->orderBy('sort_order')->orderBy('name_en')->orderBy('id')->get(),
        );
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request): Category {
            $category = new Category($request->safe()->except('parent_id'));
            $category->parent_id = $this->parentId($request->validated('parent_id'));

            [, $after] = Changes::pending($category);
            $category->save();
            $this->audit->record($this->actor($request), 'category.create', $category, after: $after);

            return $category;
        });

        return AdminCategoryResource::make($category->load('parent'))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, string $category): AdminCategoryResource
    {
        $updated = DB::transaction(function () use ($request, $category): Category {
            // One tree change at a time, so two moves cannot form a loop between them.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('categories.tree'))");
            $model = Category::query()->where('uuid', $category)->lockForUpdate()->firstOrFail();
            $model->fill($request->safe()->except('parent_id'));
            $model->parent_id = $this->parentId($request->validated('parent_id'));

            if ($model->parent_id !== null && $this->wouldCycle($model)) {
                throw ApiException::unprocessable('category_cycle', 'parent_id');
            }

            [$before, $after] = Changes::pending($model);
            $model->save();

            if ($after !== []) {
                $this->audit->record($this->actor($request), 'category.update', $model, $before, $after);
            }

            return $model;
        });

        return AdminCategoryResource::make($updated->load('parent'));
    }

    private function parentId(?string $uuid): ?int
    {
        return $uuid === null ? null : Category::query()->where('uuid', $uuid)->value('id');
    }

    /**
     * True when the new parent is the category itself or one of its descendants.
     */
    private function wouldCycle(Category $category): bool
    {
        $seen = [];
        $id = $category->parent_id;

        while ($id !== null) {
            if ($id === $category->id || isset($seen[$id])) {
                return true;
            }

            $seen[$id] = true;
            $id = Category::query()->whereKey($id)->value('parent_id');
        }

        return false;
    }
}
