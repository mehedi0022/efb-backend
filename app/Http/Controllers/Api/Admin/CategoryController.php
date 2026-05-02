<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    private const DELETE_BLOCKED_MESSAGE = 'This category cannot be deleted because it contains subcategories or products. Please remove those items first before deleting the category.';

    private function resolveCategoryIds(Request $request): array
    {
        $ids = $request->input('category_ids', $request->input('ids', []));
        if (!is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn ($id) => is_numeric($id))
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function categoryQueryWithDependencyCounts()
    {
        return Category::query()->addSelect([
            'subcategories_count' => Subcategory::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('subcategories.category_id', 'categories.id'),
            'products_count' => Product::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('products.category_id', 'categories.id'),
        ]);
    }

    /**
     * Get all categories
     */
    public function index(Request $request)
    {
        try {
            $query = $this->categoryQueryWithDependencyCounts();

            // Search
            if ($request->filled('keyword')) {
                $query->where('name', 'LIKE', '%' . $request->keyword . '%');
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 20);
            $categories = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $categories->map(function($category) {
                    return $this->formatCategory($category);
                }),
                'pagination' => [
                    'total' => $categories->total(),
                    'per_page' => $categories->perPage(),
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching categories: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single category
     */
    public function show($id)
    {
        try {
            $category = $this->categoryQueryWithDependencyCounts()->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $this->formatCategory($category),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }
    }

    /**
     * Create category
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        try {
            $category = new Category();
            $category->name = $request->name;
            $category->slug = Str::slug($request->name);
            $category->status = $request->status ?? 1;
            $category->save();
            $category = $this->categoryQueryWithDependencyCounts()->findOrFail($category->id);

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $this->formatCategory($category),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating category: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update category
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
        ]);

        try {
            $category = Category::findOrFail($id);
            $category->name = $request->name;
            $category->slug = Str::slug($request->name);
            if ($request->filled('status')) {
                $category->status = $request->status;
            }
            $category->save();
            $category = $this->categoryQueryWithDependencyCounts()->findOrFail($category->id);

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $this->formatCategory($category),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating category: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete categories
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'category_ids' => 'nullable|array',
            'ids' => 'nullable|array',
        ]);

        try {
            $categoryIds = $this->resolveCategoryIds($request);
            if (empty($categoryIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid category ids provided.',
                ], 422);
            }

            $categories = $this->categoryQueryWithDependencyCounts()
                ->whereIn('id', $categoryIds)
                ->get();

            if ($categories->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching categories found.',
                ], 404);
            }

            $blockedCategories = $categories->filter(function ($category) {
                return (int) ($category->subcategories_count ?? 0) > 0
                    || (int) ($category->products_count ?? 0) > 0;
            });

            if ($blockedCategories->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => self::DELETE_BLOCKED_MESSAGE,
                    'blocked_categories' => $blockedCategories->map(function ($category) {
                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                            'subcategories_count' => (int) ($category->subcategories_count ?? 0),
                            'products_count' => (int) ($category->products_count ?? 0),
                        ];
                    })->values(),
                ], 422);
            }

            Category::whereIn('id', $categories->pluck('id')->all())->delete();

            return response()->json([
                'success' => true,
                'message' => 'Categories deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting categories: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update status
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'category_ids' => 'required|array',
            'status' => 'required|integer|in:0,1',
        ]);

        try {
            Category::whereIn('id', $request->category_ids)
                ->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle show on home (max 3)
     */
    public function toggleShowHome(Request $request)
    {
        $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'show_home' => 'required|integer|in:0,1',
        ]);

        try {
            // If enabling, check max limit
            if ((int) $request->show_home === 1) {
                $currentCount = Category::where('show_home', 1)
                    ->where('id', '!=', $request->category_id)
                    ->count();

                if ($currentCount >= 3) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Maximum 3 categories can be shown on the home page.',
                    ], 422);
                }
            }

            Category::where('id', $request->category_id)
                ->update(['show_home' => $request->show_home]);

            return response()->json([
                'success' => true,
                'message' => $request->show_home ? 'Category added to home page.' : 'Category removed from home page.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error toggling show home: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format category
     */
    private function formatCategory($category)
    {
        $subcategoriesCount = (int) ($category->subcategories_count ?? 0);
        $productsCount = (int) ($category->products_count ?? 0);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'status' => $category->status,
            'status_text' => $category->status ? 'Active' : 'Inactive',
            'show_home' => (int) $category->show_home,
            'show_home_text' => $category->show_home ? 'Yes' : 'No',
            'subcategories_count' => $subcategoriesCount,
            'products_count' => $productsCount,
            'can_delete' => $subcategoriesCount === 0 && $productsCount === 0,
            'created_at' => $category->created_at ? $category->created_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
