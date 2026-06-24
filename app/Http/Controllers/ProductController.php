<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    protected $imageService;

    public function __construct(ImageUploadService $imageService)
    {
        $this->imageService = $imageService;
    }

    /**
     * Display a listing of products with filters
     */
    public function index(Request $request)
    {
        $tenant = app('tenant');

        $products = Product::with(['category'])
            ->where('tenant_id', $tenant->tenant_id)
            
            // Search filter
            ->when($request->search, function ($query, $search) {
                $query->search($search);
            })
            // Category filter
            ->when($request->category_id, function ($query, $categoryId) {
                $query->where('category_id', $categoryId);
            })
            // Active/inactive filter
            ->when($request->is_active !== null, function ($query) use ($request) {
                $query->where('is_active', $request->is_active);
            })
            // Stock status filter
            ->when($request->stock_status, function ($query, $status) {
                if ($status === 'in_stock') {
                    $query->inStock();
                } elseif ($status === 'low_stock') {
                    $query->lowStock();
                } elseif ($status === 'out_of_stock') { 
                    $query->where('stock_quantity', 0);
                }
            })
            // Price range filter
            ->when($request->min_price, function ($query, $minPrice) {
                $query->where('base_price', '>=', $minPrice);
            })
            ->when($request->max_price, function ($query, $maxPrice) {
                $query->where('base_price', '<=', $maxPrice);
            })
            // Sorting
            ->when($request->sort_by, function ($query, $sortBy) use ($request) {
                // Allow only asc or desc
                $sortOrder = strtolower($request->sort_order ?? 'asc');

                if (!in_array($sortOrder, ['asc', 'desc'])) {
                    $sortOrder = 'asc';
                }
                
                switch ($sortBy) {
                    case 'name':
                        $query->orderBy('product_name', $sortOrder);
                        break;
                    case 'price':
                        $query->orderBy('base_price', $sortOrder);
                        break;
                    case 'stock':
                        $query->orderBy('stock_quantity', $sortOrder);
                        break;
                    case 'created':
                        $query->orderBy('created_at', $sortOrder);
                        break;
                    default:
                        $query->latest();
                }
            }, function ($query) {
                $query->latest();
            })
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $products->items(),
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'product_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('products')->where('tenant_id', $tenant->tenant_id),
            ],
            'category_id' => 'nullable|uuid|exists:product_categories,category_id',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'image_url' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Upload image if provided
            if ($request->hasFile('image_url')) {
                $validated['image_url'] = $this->imageService->uploadProductImage(
                    $request->file('image_url'),
                    $tenant->tenant_id
                );
            }

            // Create product
            $product = Product::create([
                'tenant_id' => $tenant->tenant_id,
                'category_id' => $validated['category_id'] ?? null,
                'product_name' => $validated['product_name'],
                'product_code' => $validated['product_code'],
                'description' => $validated['description'] ?? null,
                'base_price' => $validated['base_price'],
                'cost_price' => $validated['cost_price'] ?? 0,
                'stock_quantity' => $validated['stock_quantity'] ?? 0,
                'image_url' => $validated['image_url'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->load('category'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            // Delete uploaded image if failed
            if (isset($validated['image_url'])) {
                $this->imageService->deleteImage($validated['image_url']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Product creation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified product
     */
    public function show(string $id)
    {
        $tenant = app('tenant');

        $product = Product::with('category')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('product_id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product,
                'profit_margin' => $product->profit_margin,
                'is_low_stock' => $product->is_low_stock,
            ],
        ]);
    }

    /**
     * Update the specified product
     */
    public function update(Request $request,string $id)
    {
        $tenant = app('tenant');

        $product = Product::with('category')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('product_id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'product_name' => 'sometimes|string|max:255',
            'product_code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('products')
                    ->where('tenant_id', $tenant->tenant_id)
                    ->ignore($product->product_id, 'product_id'),
            ],
            'category_id' => 'nullable|uuid|exists:product_categories,category_id',
            'description' => 'nullable|string',
            'base_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'image_url' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Upload new image if provided
            if ($request->hasFile('image_url')) {
                // Delete old image
                if ($product->image_url) {
                    $this->imageService->deleteImage($product->image_url);
                }

                $validated['image_url'] = $this->imageService->uploadProductImage(
                    $request->file('image_url'),
                    $tenant->tenant_id
                );
            }

            $product->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh('category'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Product update failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified product
     */
    public function destroy(string $id)
    {
        $tenant = app('tenant');

        $product = Product::with('category')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('product_id', $id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Delete image
            if ($product->image_url) {
                $this->imageService->deleteImage($product->image_url);
            }

            $product->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Product deletion failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle product status
     */
    public function toggleStatus(string $id)
    {
        $tenant = app('tenant');

        $product = Product::with('category')
            ->where('tenant_id', $tenant->tenant_id)
            ->where('product_id', $id)
            ->firstOrFail();

        $product->update([
            'is_active' => !$product->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $product->is_active 
                ? 'Product activated successfully' 
                : 'Product deactivated successfully',
            'data' => $product,
        ]);
    }

    /**
     * Bulk update stock
     */
    public function bulkUpdateStock(Request $request)
    {
        $tenant = app('tenant');
        
        $product = null;
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.product_id' => 'required|uuid|exists:products,product_id',
            'products.*.stock_quantity' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['products'] as $item) {
                $product = Product::with('category')
                    ->where('tenant_id', $tenant->tenant_id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if ($product) {
                    $product->update([
                        'stock_quantity' => $item['stock_quantity'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Stock updated successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Stock update failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get low stock products
     */
    public function lowStock(Request $request)
    {
        $tenant = app('tenant');

        $products = Product::with('category')
            ->where('tenant_id', $tenant->tenant_id)
            ->lowStock()
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $products->items(),
            'pagination' => [
                'total' => $products->total(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }
}