<?php

namespace App\Http\Controllers;

use App\Models\ResourceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ResourceCategoryController — resource categories (read-only for ordinary users).
 */
class ResourceCategoryController extends Controller
{
    /**
     * List active resource categories.
     *
     * GET /api/v1/resource-categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = ResourceCategory::active()
            ->ordered()
            ->get();

        return response()->json($categories);
    }

    /**
     * Get a specific category.
     *
     * GET /api/v1/resource-categories/{category}
     */
    public function show(Request $request, ResourceCategory $category): JsonResponse
    {
        return response()->json($category);
    }
}
