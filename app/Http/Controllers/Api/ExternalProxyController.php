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

    private function attributeSlug(mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
        return trim($slug, '-');
    }

    private function attributeValue(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        return preg_replace('/\s+/', '-', $text) ?: $text;
    }

    private function normalizeVariantAttribute(array $attr): array
    {
        $attributeName = data_get($attr, 'attributeName', data_get($attr, 'attribute_name', data_get($attr, 'value.attribute.name', '')));
        $label = data_get($attr, 'label', data_get($attr, 'valueName', data_get($attr, 'value_name', data_get($attr, 'value.value', data_get($attr, 'value', '')))));
        $attributeSlug = data_get($attr, 'attributeSlug', data_get($attr, 'attribute_slug', $this->attributeSlug($attributeName)));
        $value = data_get($attr, 'value', $this->attributeValue($label));

        return [
            'attributeId' => data_get($attr, 'attributeId', data_get($attr, 'attribute_id', data_get($attr, 'value.attribute.id'))),
            'attribute_id' => data_get($attr, 'attribute_id', data_get($attr, 'attributeId', data_get($attr, 'value.attribute.id'))),
            'attributeName' => $attributeName,
            'attribute_name' => $attributeName,
            'attributeSlug' => $attributeSlug,
            'attribute_slug' => $attributeSlug,
            'valueId' => data_get($attr, 'valueId', data_get($attr, 'value_id', data_get($attr, 'value.id'))),
            'value_id' => data_get($attr, 'value_id', data_get($attr, 'valueId', data_get($attr, 'value.id'))),
            'value' => $value,
            'valueName' => $label,
            'label' => $label,
            'hex' => data_get($attr, 'hex'),
        ];
    }

    private function normalizeVariation(array $variant, mixed $sellerPrice = null): array
    {
        $price = data_get($variant, 'price', data_get($variant, 'suggestedPrice', data_get($variant, 'suggested_price', $sellerPrice)));
        $image = $this->toImageUrl((string) data_get($variant, 'image', ''));

        return [
            'id' => data_get($variant, 'id'),
            'sku' => data_get($variant, 'sku', ''),
            'price' => (float) ($price ?? 0),
            'suggestedPrice' => (float) (data_get($variant, 'suggestedPrice', data_get($variant, 'suggested_price', $price)) ?? 0),
            'suggested_price' => (float) (data_get($variant, 'suggested_price', data_get($variant, 'suggestedPrice', $price)) ?? 0),
            'wholesalePrice' => (float) (data_get($variant, 'wholesalePrice', data_get($variant, 'wholesale_price', 0)) ?? 0),
            'wholesale_price' => (float) (data_get($variant, 'wholesale_price', data_get($variant, 'wholesalePrice', 0)) ?? 0),
            'stock' => (int) (data_get($variant, 'stock', 0) ?? 0),
            'inStock' => (bool) data_get($variant, 'inStock', data_get($variant, 'in_stock', ((int) data_get($variant, 'stock', 0)) > 0)),
            'in_stock' => (bool) data_get($variant, 'in_stock', data_get($variant, 'inStock', ((int) data_get($variant, 'stock', 0)) > 0)),
            'image' => $image !== '' ? $image : null,
            'images' => array_values(array_filter(array_map(fn($img) => $this->toImageUrl((string) data_get($img, 'url', data_get($img, 'image', $img))), (array) data_get($variant, 'images', [])))),
            'attributes' => array_map(fn($attr) => $this->normalizeVariantAttribute((array) $attr), (array) data_get($variant, 'attributes', [])),
        ];
    }

    private function normalizeAttributeGroups(array $item, array $variations): array
    {
        $groups = data_get($item, 'attributes', []);
        if (is_array($groups) && count($groups) > 0) {
            return array_map(function ($group) {
                $name = data_get($group, 'name', '');
                $slug = data_get($group, 'slug', $this->attributeSlug($name));
                return [
                    'id' => data_get($group, 'id'),
                    'name' => $name,
                    'slug' => $slug,
                    'values' => array_values(array_map(fn($value) => [
                        'id' => data_get($value, 'id'),
                        'label' => data_get($value, 'label', data_get($value, 'value', '')),
                        'value' => data_get($value, 'value', $this->attributeValue(data_get($value, 'label', ''))),
                        'hex' => data_get($value, 'hex'),
                    ], (array) data_get($group, 'values', []))),
                ];
            }, $groups);
        }

        $bySlug = [];
        foreach ($variations as $variation) {
            foreach ((array) ($variation['attributes'] ?? []) as $attr) {
                $slug = $attr['attributeSlug'] ?? $attr['attribute_slug'] ?? '';
                $value = $attr['value'] ?? '';
                if ($slug === '' || $value === '') continue;
                $bySlug[$slug] ??= [
                    'id' => $attr['attributeId'] ?? $attr['attribute_id'] ?? null,
                    'name' => $attr['attributeName'] ?? $attr['attribute_name'] ?? $slug,
                    'slug' => $slug,
                    'values' => [],
                ];
                $exists = collect($bySlug[$slug]['values'])->contains(fn($row) => ($row['value'] ?? '') === $value);
                if (!$exists) {
                    $bySlug[$slug]['values'][] = [
                        'id' => $attr['valueId'] ?? $attr['value_id'] ?? null,
                        'label' => $attr['label'] ?? $attr['valueName'] ?? $value,
                        'value' => $value,
                        'hex' => $attr['hex'] ?? null,
                    ];
                }
            }
        }

        return array_values($bySlug);
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
        $variations = array_map(fn($v) => $this->normalizeVariation((array) $v, $priceValue), (array) ($row['variations'] ?? $row['variants'] ?? []));
        $attributes = $this->normalizeAttributeGroups($row, $variations);
        $type = data_get($row, 'type', data_get($row, 'productType', count($attributes) > 0 || count($variations) > 1 ? 'variable' : 'simple'));

        $totalStock = (int) data_get($row, 'totalStock', data_get($row, 'summary.totalStock', array_sum(array_map(fn($v) => (int) ($v['stock'] ?? 0), $variations))));

        return [
            'id' => $row['id'] ?? null,
            'type' => $type,
            'product_type' => $type,
            'price' => (float) ($priceValue ?? 0),
            'previous_price' => null,
            'available_stock' => $totalStock,
            'attributes' => $attributes,
            'variations' => $variations,
            'variants' => $variations,
            'product_info' => [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? '',
                'slug' => $row['slug'] ?? '',
                'type' => $type,
                'product_type' => $type,
                'sku' => data_get($variations, '0.sku'),
                'thumbnail' => $this->toImageUrl($row['thumbnail'] ?? $row['coverImage'] ?? ''),
                'price' => (float) ($priceValue ?? 0),
                'new_price' => (float) ($priceValue ?? 0),
                'old_price' => data_get($row, 'sellerProduct.previousePrice'),
                'available_stock' => $totalStock,
                'attributes' => $attributes,
                'variations' => $variations,
                'variants' => $variations,
                'panel_product_id' => $row['id'] ?? null,
                'panel_variant_id' => data_get($variations, '0.id'),
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
        $rawVariants = (array) ($item['variations'] ?? $item['variants'] ?? []);
        $variant = ($rawVariants[0] ?? []);
        $basePrice = data_get($item, 'summary.priceRange.value', data_get($item, 'summary.priceRange.min', 0));
        $price = data_get($item, 'sellerProduct.price', $basePrice);
        $variations = array_map(fn($v) => $this->normalizeVariation((array) $v, $price), $rawVariants);
        $attributes = $this->normalizeAttributeGroups((array) $item, $variations);
        $type = data_get($item, 'type', data_get($item, 'productType', count($attributes) > 0 || count($variations) > 1 ? 'variable' : 'simple'));
        $findAttributeValues = function (string $slug) use ($attributes) {
            $group = collect($attributes)->first(fn($attr) => ($attr['slug'] ?? '') === $slug);
            if (!$group) return [];
            return array_map(fn($value) => [
                'id' => $value['id'] ?? $value['value'] ?? null,
                'name' => $value['label'] ?? $value['value'] ?? '',
                'value' => $value['value'] ?? '',
                'hex' => $value['hex'] ?? null,
            ], (array) ($group['values'] ?? []));
        };

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
                'type' => $type,
                'productType' => $type,
                'product_type' => $type,
                'productName' => $item['name'] ?? '',
                'price' => (float) ($price ?? 0),
                'previousPrice' => data_get($item, 'sellerProduct.previousePrice'),
                'sku' => data_get($variant, 'sku', ''),
                'category_name' => data_get($item, 'category.name', ''),
                'description' => $item['description'] ?? '',
                'available_stock' => (int) data_get($item, 'summary.totalStock', 0),
                'images' => array_map(fn($img) => ['image' => $img], $allImages),
                'attributes' => $attributes,
                'variations' => $variations,
                'variants' => $variations,
                'sizes' => $findAttributeValues('size'),
                'colors' => $findAttributeValues('color'),
                'panel_product_id' => $item['id'] ?? null,
                'panel_variant_id' => data_get($variant, 'id'),
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

        $query = $this->sellerScopeQuery();
        $districtId = $request->query('districtId', $request->query('district_id', $request->query('district')));
        if ($districtId !== null && $districtId !== '') {
            $query['districtId'] = $districtId;
        }

        $response = $this->http()->get(
            $this->baseUrl() . '/api/v1/product/public/shipping-charge',
            $query
        );
        if (!$response->successful()) {
            return $this->externalError($response);
        }

        $row = $response->json('data') ?? [];
        $zones = data_get($row, 'zones', []);
        if (!is_array($zones) || count($zones) === 0) {
            $zones = [$row];
        }

        return response()->json([
            'success' => true,
            'data' => array_values(array_map(function ($zone) {
                $name = trim((string) data_get($zone, 'name', data_get($zone, 'zoneName', 'Delivery Charge')));
                return [
                    'id' => data_get($zone, 'id', 1),
                    'zone_name' => data_get($zone, 'zoneName', data_get($zone, 'zone_name')),
                    'zone_slug' => data_get($zone, 'zoneSlug', data_get($zone, 'zone_slug')),
                    'name' => $name !== '' ? $name : 'Delivery Charge',
                    'amount' => (float) data_get($zone, 'amount', 0),
                    'district_mode' => data_get($zone, 'districtMode', data_get($zone, 'district_mode', 'all')),
                    'district_ids' => data_get($zone, 'districtIds', data_get($zone, 'district_ids', [])),
                ];
            }, $zones)),
            'meta' => [
                'source' => 'external_seller_shipping_charge',
                'district_id' => $districtId,
            ],
        ]);
    }
}
