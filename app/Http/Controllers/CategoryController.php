<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    protected $imageService;

    public function __construct(ImageUploadService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * Display a listing of categories
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $categories = ProductCategory::with('parent')
            ->where('tenant_id', $tenant->tenant_id)
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('category_name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->is_active !== null, function ($query) use ($request) {
                $query->where('is_active', $request->is_active);
            })
            ->when($request->parent, function ($query) {
                $query->rootCategories(); 
            })
            ->withCount('products')
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $categories->items(),
            'pagination' => [
                'total' => $categories->total(),
                'per_page' => $categories->perPage(),
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created category
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'category_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_category_id' => 'nullable|uuid|exists:product_categories,category_id',
            'image_url' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'nullable|boolean',
        ]);

        // Upload image if provided
        if ($request->hasFile('image_url')) {
            $validated['image_url'] = $this->imageService->uploadCategoryImage(
                $request->file('image_url'),
                $tenant->tenant_id
            );
        }

        $category = ProductCategory::create([
            'tenant_id' => $tenant->tenant_id,
            'category_name' => $validated['category_name'],
            'description' => $validated['description'] ?? null,
            'parent_category_id' => $validated['parent_category_id'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category->load('parent'),
        ], 201);
    }

    /**
     * Display the specified category
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $category = ProductCategory::with(['parent', 'children'])
            ->where('tenant_id', $tenant->tenant_id)
            ->where('category_id', $id)
            ->withCount('products')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    /**
     * Update the specified category
     */
    public function update(Request $request, string $id)
    {
        $tenant = app('tenant');

        $category = ProductCategory::with('parent')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('category_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'category_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'parent_category_id' => 'nullable|uuid|exists:product_categories,category_id',
            'image_url' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'sometimes|boolean',
        ]);

        // Upload new image if provided
        if ($request->hasFile('image_url')) {
            // Delete old image
            if ($category->image_url) {
                $this->imageService->deleteImage($category->image_url);
            }

            $validated['image_url'] = $this->imageService->uploadCategoryImage(
                $request->file('image_url'),
                $tenant->tenant_id
            );
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category->load('parent'),
        ]);
    }

    /**
     * Remove the specified category
     */
    public function destroy(string $id)
    {
        $tenant = app('tenant');

        $category = ProductCategory::with('parent')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('category_id', $id)
            ->withCount('products')
            ->firstOrFail();

        // Check if category has child categories
        if ($category->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with subcategories',
                'subcategories_count' => $category->children()->count(),
            ], 409);
        }

        // Check if category has products
        if ($category->products_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with products',
                'products_count' => $category->products_count,
            ], 409);
        }

        // Delete image
        if ($category->image_url) {
            $this->imageService->deleteImage($category->image_url);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Toggle category status
     */
    public function toggleStatus(string $id)
    {
        $tenant = app('tenant');

        $category = ProductCategory::with('parent')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('category_id', $id)
            ->firstOrFail();

        $category->update([
            'is_active' => !$category->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $category->is_active 
                ? 'Category activated successfully.' 
                : 'Category deactivated successfully.',
            'data' => $category,
        ]);
    }
}