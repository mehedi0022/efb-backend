<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected InventoryService $inventoryService
    )
    {
    }

    public function home(): JsonResponse
    {
        return response()->json($this->productService->getHomeData());
    }

    public function hotDeals(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 12);
        return response()->json($this->productService->getHotDeals($limit));
    }

    public function latest(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 12);
        return response()->json($this->productService->getLatest($limit));
    }

    public function newArrivals(Request $request): JsonResponse
    {
        $limit = $request->input('limit');
        return response()->json($this->productService->getNewArrivals($limit ? (int) $limit : null));
    }

    public function details(string $slug): JsonResponse
    {
        return response()->json($this->productService->getProductDetails($slug));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'category_id',
            'subcategory_id',
            'childcategory_id',
            'search',
            'limit',
        ]);
        return response()->json($this->productService->getProducts($filters));
    }

    /**
     * Get categories marked as show_home with their products
     */
    public function homeCategories(): JsonResponse
    {
        $categories = \App\Models\Category::where('status', 1)
            ->where('show_home', 1)
            ->with(['products' => function ($query) {
                $query->where('status', 1)
                    ->with('image')
                    ->select('id', 'name', 'slug', 'category_id', 'new_price', 'old_price', 'product_code as sku', 'stock')
                    ->orderBy('id', 'DESC')
                    ->limit(12);
            }])
            ->select('id', 'name', 'slug', 'image')
            ->limit(3)
            ->get();

        $stockMap = $this->inventoryService->fetchAvailabilityMap(
            $categories->pluck('products')->flatten()->pluck('sku')->filter()->unique()->values()->all()
        );

        $categories = $categories->map(function ($category) use ($stockMap) {
            $category->setRelation(
                'products',
                $this->inventoryService->attachAvailability($category->products, $stockMap)
            );
            return $category;
        });

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
