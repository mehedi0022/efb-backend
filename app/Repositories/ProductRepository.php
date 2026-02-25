<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductRepository
{
    public function getHotDeals(int $limit = 12): LengthAwarePaginator
    {
        return Product::where(['status' => 1, 'topsale' => 1])
            ->with('image')
            ->select('id', 'name', 'slug', 'new_price', 'old_price', 'category_id', 'product_code as sku')
            ->orderBy('id', 'DESC')
            ->paginate($limit);
    }

    public function getLatest(int $limit = 12): LengthAwarePaginator
    {
        return Product::where(['status' => 1])
            ->with('image')
            ->select('id', 'name', 'slug', 'new_price', 'old_price', 'category_id', 'product_code as sku')
            ->orderBy('id', 'DESC')
            ->paginate($limit);
    }

    public function getNewArrivals(?int $limit = null)
    {
        $query = Product::where(['status' => 1, 'view_home' => 1])
            ->with('image')
            ->select('id', 'name', 'slug', 'new_price', 'old_price', 'category_id', 'product_code as sku');

        if ($limit) {
            return $query->orderBy('id', 'DESC')->paginate($limit);
        }

        return $query->orderBy('id', 'DESC')->get();
    }

    public function findBySlug(string $slug): Product
    {
        return Product::where(['slug' => $slug, 'status' => 1])
            ->with([
                'image',
                'images',
                'featureImage',
                'galleryImages',
                'category',
                'prosizes.size',
                'sizePricings.size',
                'procolors.color',
            ])
            ->firstOrFail();
    }

    public function getRelatedProducts(int $categoryId, int $limit = 12): Collection
    {
        return Product::where(['category_id' => $categoryId, 'status' => 1])
            ->with('image')
            ->select('id', 'name', 'slug', 'new_price', 'old_price', 'category_id', 'product_code as sku')
            ->take($limit)
            ->get();
    }
}
