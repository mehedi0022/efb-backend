<?php

namespace App\Services;

use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\Category;
use Illuminate\Support\Facades\Schema;

class ProductService
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected InventoryService $inventoryService
    ) {}

    public function getHomeData(): array
    {
        // Logic from FrontendController::index
        // Fetch categories with products
        $homeproducts = Category::where('status', 1)
            ->orderBy('id', 'ASC')
            ->with([
                'products' => function ($query) {
                    $query->where('status', 1)
                        ->orderBy('id', 'DESC')
                        ->select('id', 'name', 'slug', 'new_price', 'old_price', 'is_size_price', 'category_id', 'sku', 'product_type');
                },
                'products.image',
                'products.prosize',
                'products.procolor'
            ])
            ->get();

        // Attach availability
        $availabilityMap = $this->inventoryService->fetchAvailabilityMap(
             $homeproducts->pluck('products')->flatten()->pluck('sku')->filter()->unique()->values()->all()
        );

        $homeproducts = $homeproducts->map(function ($category) use ($availabilityMap) {
            $category->setRelation('products', $this->inventoryService->attachAvailability($category->products, $availabilityMap));
            return $category;
        });

        return [
            'home_products' => $homeproducts
        ];
    }

    public function getHotDeals(int $limit = 12): LengthAwarePaginator
    {
        $products = $this->productRepository->getHotDeals($limit);
        return $this->inventoryService->attachAvailability($products);
    }

    public function getLatest(int $limit = 12): LengthAwarePaginator
    {
        $products = $this->productRepository->getLatest($limit);
        return $this->inventoryService->attachAvailability($products);
    }

    public function getNewArrivals(?int $limit = null)
    {
        $products = $this->productRepository->getNewArrivals($limit);
        if ($products instanceof LengthAwarePaginator) {
            return $this->inventoryService->attachAvailability($products);
        }

        return $this->inventoryService->attachAvailability($products);
    }

    public function getProducts(array $filters)
    {
        $categoryId = $this->normalizePositiveInt($filters['category_id'] ?? null);
        $subcategoryId = $this->normalizePositiveInt($filters['subcategory_id'] ?? null);
        $childcategoryId = $this->normalizePositiveInt($filters['childcategory_id'] ?? null);

        $query = \App\Models\Product::query()
            ->where('status', 1)
            ->select([
                'id',
                'name',
                'slug',
                'new_price',
                'old_price',
                'category_id',
                'brand_id',
                'product_code as sku',
                'stock',
            ])
            ->with([
                'category:id,name,slug',
                'image:id,product_id,image',
                'brand:id,name,slug',
            ]);

        $this->applyCategoryFilters($query, $categoryId, $subcategoryId, $childcategoryId);

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        $products = $query->paginate($filters['limit'] ?? 20);

        return $this->inventoryService->attachAvailability($products);
    }

    private function applyCategoryFilters(
        Builder $query,
        ?int $categoryId,
        ?int $subcategoryId,
        ?int $childcategoryId
    ): void
    {
        if ($childcategoryId !== null && Schema::hasColumn('products', 'childcategory_id')) {
            $query->where('childcategory_id', $childcategoryId);
            return;
        }

        if ($subcategoryId !== null && Schema::hasColumn('products', 'subcategory_id')) {
            $query->where('subcategory_id', $subcategoryId);
            return;
        }

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    public function getProductDetails(string $slug): array
    {
        $product = $this->productRepository->findBySlug($slug);
        if (!$product->getAttribute('sku') && $product->getAttribute('product_code')) {
            $product->setAttribute('sku', $product->getAttribute('product_code'));
        }
        $product = $this->inventoryService->attachFullInventory($product);

        $related = $this->productRepository->getRelatedProducts($product->category_id);
        $related = $this->inventoryService->attachAvailability($related);

        return [
            'product' => $product,
            'related_products' => $related,
        ];
    }
}
