<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ExternalProxyController extends Controller
{
    private function sellerCode(): string
    {
        return trim((string) config('services.panel.seller_code', ''));
    }

    private function userDomain(): string
    {
        return trim((string) config('services.panel.user_domain', ''));
    }

    private function sellerScopeQuery(): array
    {
        return [
            'sellerCode' => $this->sellerCode(),
            'userDomain' => $this->userDomain(),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.api.base_url', ''), '/');
    }

    private function ensureConfigured()
    {
        if ($this->baseUrl() === '') {
            return response()->json([
                'success' => false,
                'message' => 'External API is not configured. Set API_BASE_URL in backend environment.',
            ], 500);
        }
        if ($this->sellerCode() === '' || $this->userDomain() === '') {
            return response()->json([
                'success' => false,
                'message' => 'Seller scope is missing. Set EFB_SELLER_CODE and EFB_USER_DOMAIN in backend environment.',
            ], 500);
        }

        return null;
    }

    private function http()
    {
        return Http::timeout(20)->connectTimeout(5)->acceptJson();
    }

    private function externalError($response)
    {
        $payload = $response->json();
        $message = data_get($payload, 'message');
        if (!is_string($message) || trim($message) === '') {
            $message = 'External API request failed.';
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'upstream' => $payload,
        ], $response->status());
    }

    private function toImageUrl(?string $path): string
    {
        $raw = trim((string) ($path ?? ''));
        if ($raw === '') return '';
        if (preg_match('/^https?:\/\//i', $raw)) return $raw;

        $base = rtrim((string) config('services.api.external_image_base', ''), '/');
        if ($base === '') {
            $base = $this->baseUrl();
        }
        if ($base === '') return $raw;
        return $base . '/' . ltrim($raw, '/');
    }

    private function mapProduct(array $row): array
    {
        $priceValue = data_get($row, 'sellerProduct.price');
        if ($priceValue === null) {
            $priceValue = data_get($row, 'suggestedPrice.value');
        }
        if ($priceValue === null) {
            $priceValue = data_get($row, 'suggestedPrice.min', 0);
        }

        return [
            'id' => $row['id'] ?? null,
            'price' => (float) ($priceValue ?? 0),
            'previous_price' => null,
            'available_stock' => (int) ($row['totalStock'] ?? 0),
            'product_info' => [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? '',
                'slug' => $row['slug'] ?? '',
                'thumbnail' => $this->toImageUrl($row['thumbnail'] ?? $row['coverImage'] ?? ''),
                'price' => (float) ($priceValue ?? 0),
                'new_price' => (float) ($priceValue ?? 0),
                'old_price' => data_get($row, 'sellerProduct.previousePrice'),
                'available_stock' => (int) ($row['totalStock'] ?? 0),
                'panel_product_id' => $row['id'] ?? null,
                'panel_variant_id' => data_get($row, 'variants.0.id'),
                'panel_seller_product_id' => data_get($row, 'sellerProduct.id'),
            ],
        ];
    }

    private function getCategoriesFlat(): array
    {
        $response = $this->http()->get($this->baseUrl() . '/api/v1/product/public/categories', $this->sellerScopeQuery());
        if (!$response->successful()) return [];
        $payload = $response->json();

        $tree = is_array($payload) ? ($payload['data'] ?? []) : [];
        $flat = [];

        $walk = function ($nodes, $parent = null) use (&$walk, &$flat) {
            foreach ((array) $nodes as $node) {
                $flat[] = [
                    'id' => $node['id'] ?? null,
                    'name' => $node['name'] ?? '',
                    'slug' => $node['slug'] ?? '',
                    'parentId' => $node['parentId'] ?? $parent,
                ];
                $walk($node['children'] ?? [], $node['id'] ?? null);
            }
        };
        $walk($tree);
        return $flat;
    }

    public function featuredCategories(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $categories = $this->getCategoriesFlat();
        $topLevel = array_values(array_filter($categories, fn($c) => empty($c['parentId'])));
        $limit = max(1, (int) $request->input('limit', 20));

        return response()->json([
            'success' => true,
            'data' => array_slice(array_map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'slug' => $c['slug'],
                'category_name' => $c['name'],
                'category_slug' => $c['slug'],
            ], $topLevel), 0, $limit),
            'meta' => ['page' => 1, 'last_page' => 1],
        ]);
    }

    public function menuCategories(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $categories = $this->getCategoriesFlat();
        $byParent = [];
        foreach ($categories as $category) {
            $byParent[$category['parentId'] ?? 0][] = $category;
        }

        $topLevel = $byParent[0] ?? [];
        $data = array_map(function ($category) use ($byParent) {
            $children = $byParent[$category['id']] ?? [];
            return [
                'id' => $category['id'],
                'category_name' => $category['name'],
                'category_slug' => $category['slug'],
                'childern' => array_map(fn($child) => [
                    'id' => $child['id'],
                    'category_name' => $child['name'],
                    'category_slug' => $child['slug'],
                ], $children),
            ];
        }, $topLevel);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => ['page' => 1, 'last_page' => 1],
        ]);
    }

    public function topSell(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $page = max(1, (int) $request->input('page', 1));
        $limit = max(1, (int) $request->input('limit', 12));

        $response = $this->http()->get($this->baseUrl() . '/api/v1/product/public', [
            ...$this->sellerScopeQuery(),
            'page' => $page,
            'limit' => $limit,
        ]);
        if (!$response->successful()) {
            return $this->externalError($response);
        }
        $payload = $response->json();
        $rows = $payload['data'] ?? [];

        return response()->json([
            'success' => true,
            'data' => array_map(fn($row) => $this->mapProduct($row), $rows),
            'meta' => [
                'page' => data_get($payload, 'meta.page', $page),
                'last_page' => data_get($payload, 'meta.totalPages', 1),
                'total' => data_get($payload, 'meta.total', count($rows)),
            ],
        ]);
    }

    public function hotDeals(Request $request)
    {
        return $this->topSell($request);
    }

    public function categoryProducts(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $prodPage = max(1, (int) $request->input('prod_page', 1));
        $prodLimit = max(1, (int) $request->input('prod_limit', 12));

        $categoryResponse = $this->http()->get($this->baseUrl() . '/api/v1/product/public/categories', $this->sellerScopeQuery());
        $productResponse = $this->http()->get($this->baseUrl() . '/api/v1/product/public', [
            ...$this->sellerScopeQuery(),
            'page' => $prodPage,
            'limit' => $prodLimit * 10,
        ]);

        if (!$categoryResponse->successful() || !$productResponse->successful()) {
            $failed = !$categoryResponse->successful() ? $categoryResponse : $productResponse;
            return $this->externalError($failed);
        }

        $categories = $this->getCategoriesFlat();
        $products = $productResponse->json()['data'] ?? [];
        $grouped = [];
        foreach ($products as $product) {
            $cat = $product['category'] ?? null;
            if (!$cat || empty($cat['id'])) continue;
            $grouped[$cat['id']][] = $this->mapProduct($product);
        }

        $data = [];
        foreach ($categories as $category) {
            if (empty($grouped[$category['id']])) continue;
            $data[] = [
                'id' => $category['id'],
                'category_name' => $category['name'],
                'category_slug' => $category['slug'],
                'products' => array_slice($grouped[$category['id']], 0, $prodLimit),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'product_pagination' => [
                    'current_page' => data_get($productResponse->json(), 'meta.page', $prodPage),
                    'last_page' => data_get($productResponse->json(), 'meta.totalPages', 1),
                ],
            ],
        ]);
    }

    public function productDetails(Request $request, string $slug)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $response = $this->http()->get($this->baseUrl() . '/api/v1/product/public/slug/' . trim($slug), $this->sellerScopeQuery());
        if (!$response->successful()) {
            return $this->externalError($response);
        }

        $item = $response->json()['data'] ?? [];
        $variant = ($item['variants'][0] ?? []);
        $basePrice = data_get($item, 'summary.priceRange.value', data_get($item, 'summary.priceRange.min', 0));
        $price = data_get($item, 'sellerProduct.price', $basePrice);

        $coverImage = $this->toImageUrl($item['coverImage'] ?? '');
        $galleryImages = array_map(
            fn($img) => $this->toImageUrl($img['url'] ?? ''),
            $item['images'] ?? []
        );
        $allImages = array_values(array_filter(array_unique(array_merge(
            $coverImage !== '' ? [$coverImage] : [],
            $galleryImages
        ))));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? '',
                'slug' => $item['slug'] ?? '',
                'productName' => $item['name'] ?? '',
                'price' => (float) ($price ?? 0),
                'previousPrice' => data_get($item, 'sellerProduct.previousePrice'),
                'sku' => $variant['sku'] ?? '',
                'category_name' => data_get($item, 'category.name', ''),
                'description' => $item['description'] ?? '',
                'available_stock' => (int) data_get($item, 'summary.totalStock', 0),
                'images' => array_map(fn($img) => ['image' => $img], $allImages),
                'sizes' => array_map(fn($v) => ['id' => $v['id'], 'name' => $v['sku'] ?? ('Variant ' . $v['id'])], $item['variants'] ?? []),
                'colors' => [],
                'variants' => array_map(fn($v) => [
                    'id' => $v['id'] ?? null,
                    'sku' => $v['sku'] ?? '',
                    'price' => (float) ($v['suggestedPrice'] ?? $price ?? 0),
                    'stock' => (int) ($v['stock'] ?? 0),
                    'attributes' => array_map(fn($a) => [
                        'attributeName' => data_get($a, 'value.attribute.name', ''),
                        'valueName' => data_get($a, 'value.value', ''),
                    ], $v['attributes'] ?? []),
                ], $item['variants'] ?? []),
                'panel_product_id' => $item['id'] ?? null,
                'panel_variant_id' => $variant['id'] ?? null,
                'panel_seller_product_id' => data_get($item, 'sellerProduct.id'),
                'related_products' => [],
            ],
        ]);
    }

    public function categoryProductsBySlug(Request $request, string $slug)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $page = max(1, (int) $request->input('page', 1));
        $limit = max(1, (int) $request->input('limit', 20));

        $categories = $this->getCategoriesFlat();
        $target = collect($categories)->first(fn($c) => ($c['slug'] ?? '') === trim($slug));
        if (!$target) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['page' => $page, 'last_page' => 1, 'total' => 0]]);
        }

        $response = $this->http()->get($this->baseUrl() . '/api/v1/product/public', [
            ...$this->sellerScopeQuery(),
            'page' => $page,
            'limit' => $limit,
            'categoryId' => $target['id'],
        ]);
        if (!$response->successful()) {
            return $this->externalError($response);
        }
        $payload = $response->json();

        return response()->json([
            'success' => true,
            'data' => array_map(fn($row) => $this->mapProduct($row), $payload['data'] ?? []),
            'meta' => [
                'page' => data_get($payload, 'meta.page', $page),
                'last_page' => data_get($payload, 'meta.totalPages', 1),
                'total' => data_get($payload, 'meta.total', 0),
            ],
        ]);
    }

    public function userSubcategoryProducts(Request $request, string $slug)
    {
        return $this->categoryProductsBySlug($request, $slug);
    }

    public function searchProducts(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;
        $page = max(1, (int) $request->input('page', 1));
        $limit = max(1, (int) $request->input('limit', 20));
        $search = trim((string) $request->input('keyword', $request->input('search', '')));

        $response = $this->http()->get($this->baseUrl() . '/api/v1/product/public', [
            ...$this->sellerScopeQuery(),
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
        ]);
        if (!$response->successful()) {
            return $this->externalError($response);
        }
        $payload = $response->json();

        return response()->json([
            'success' => true,
            'data' => array_map(fn($row) => $this->mapProduct($row), $payload['data'] ?? []),
            'meta' => [
                'page' => data_get($payload, 'meta.page', $page),
                'last_page' => data_get($payload, 'meta.totalPages', 1),
                'total' => data_get($payload, 'meta.total', 0),
            ],
        ]);
    }

    public function shippingCharges(Request $request)
    {
        if ($error = $this->ensureConfigured()) return $error;

        $response = $this->http()->get(
            $this->baseUrl() . '/api/v1/product/public/shipping-charge',
            $this->sellerScopeQuery()
        );
        if (!$response->successful()) {
            return $this->externalError($response);
        }

        $row = $response->json('data') ?? [];
        $amount = (float) data_get($row, 'amount', 0);
        $name = trim((string) data_get($row, 'name', 'Delivery Charge'));

        return response()->json([
            'success' => true,
            'data' => [
                [
                    'id' => data_get($row, 'id', 1),
                    'name' => $name !== '' ? $name : 'Delivery Charge',
                    'amount' => $amount,
                ],
            ],
            'meta' => [
                'source' => 'external_seller_shipping_charge',
            ],
        ]);
    }
}
