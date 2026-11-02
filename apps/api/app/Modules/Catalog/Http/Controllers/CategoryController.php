<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;

/**
 * Categories for customers browsing the website and app.
 */
final class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $nameColumn = App::getLocale() === 'en' ? 'name_en' : 'name_ar';

        $categories = Category::query()
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy($nameColumn)
            ->orderBy('id')
            ->get();

        return CategoryResource::collection($categories);
    }
}
