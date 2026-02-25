<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Productimage;
use App\Models\Size;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private array $productColumnCache = [];

    /**
     * Get all products with pagination and filters.
     */
    public function index(Request $request)
    {
        try {
            $query = Product::with([
                'category',
                'subcategory',
                'brand',
                'image',
                'featureImage',
                'galleryImages',
                'colors:id,colorName,color',
                'sizes:id,sizeName',
                'sizePricings.size:id,sizeName',
            ]);

            if ($request->filled('keyword')) {
                $keyword = (string) $request->keyword;
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'LIKE', "%{$keyword}%")
                        ->orWhere('product_code', 'LIKE', "%{$keyword}%")
                        ->orWhere('slug', 'LIKE', "%{$keyword}%");
                });
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->category_id);
            }

            if ($request->filled('subcategory_id') && $this->productColumnExists('subcategory_id')) {
                $query->where('subcategory_id', (int) $request->subcategory_id);
            }

            if ($request->filled('brand_id') && $this->productColumnExists('brand_id')) {
                $query->where('brand_id', (int) $request->brand_id);
            }

            if ($request->filled('status')) {
                $query->where('status', (int) $request->status);
            }

            $allowedSortBy = ['id', 'name', 'new_price', 'stock', 'created_at', 'updated_at'];
            $sortBy = (string) $request->get('sort_by', 'created_at');
            if (!in_array($sortBy, $allowedSortBy, true)) {
                $sortBy = 'created_at';
            }
            $sortOrder = strtolower((string) $request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortOrder);

            $perPage = max(1, min(200, (int) $request->get('per_page', 20)));
            $products = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products->getCollection()->map(function ($product) {
                    return $this->formatProduct($product);
                }),
                'pagination' => [
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single product.
     */
    public function show($id)
    {
        try {
            $product = Product::with([
                'category',
                'subcategory',
                'brand',
                'image',
                'images',
                'featureImage',
                'galleryImages',
                'colors',
                'sizes',
                'sizePricings.size:id,sizeName',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->formatProduct($product, true),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }
    }

    /**
     * Create new product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'product_code' => 'nullable|string|max:255',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
            'topsale' => 'nullable|integer|in:0,1',
            'feature' => 'nullable|integer|in:0,1',
            'view_home' => 'nullable|boolean',
            'colors' => 'nullable|array',
            'colors.*' => 'integer|exists:colors,id',
            'sizes' => 'nullable|array',
            'sizes.*' => 'integer|exists:sizes,id',
            'image' => 'nullable|image|max:5120',
            'feature_image' => 'nullable|image|max:5120',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'image|max:5120',
            'images' => 'nullable',
            'size_pricing' => 'nullable',
            'youtube_video_url' => 'nullable|string|max:1000',
            'facebook_video_url' => 'nullable|string|max:1000',
        ]);

        $videoPayload = $this->resolveVideoPayload($request);

        try {
            DB::beginTransaction();

            $product = new Product();
            $product->name = $validated['name'];
            $product->slug = Str::slug($validated['name']) . '-' . time();
            $product->category_id = (int) $validated['category_id'];
            if ($this->productColumnExists('subcategory_id')) {
                $product->subcategory_id = $request->filled('subcategory_id') ? (int) $request->subcategory_id : null;
            }
            if ($this->productColumnExists('brand_id')) {
                $product->brand_id = $request->filled('brand_id') ? (int) $request->brand_id : null;
            }
            $product->product_code = $this->generateUniqueProductCode();
            $product->purchase_price = (float) $validated['purchase_price'];
            $product->new_price = (float) $validated['selling_price'];
            $product->old_price = $request->filled('old_price') ? (float) $request->old_price : null;
            $product->stock = (int) $validated['stock'];
            $product->description = $request->description;
            $product->status = isset($validated['status']) ? (int) $validated['status'] : 1;
            $product->topsale = isset($validated['topsale']) ? (int) $validated['topsale'] : 0;
            $product->feature_product = isset($validated['feature']) ? (int) $validated['feature'] : 0;
            if ($this->productColumnExists('view_home')) {
                $product->view_home = $request->boolean('view_home');
            }
            $this->applyVideoPayload($product, $videoPayload);
            $product->save();

            if ($request->has('colors')) {
                $product->colors()->sync($this->normalizeIdArray($validated['colors'] ?? []));
            }

            $rawSizePricing = $this->parseArrayInput($request, 'size_pricing');
            $legacySizeIds = $this->normalizeIdArray($this->parseArrayInput($request, 'sizes'));
            $sizePricingRows = $this->normalizeSizePricingRows(
                $rawSizePricing,
                $legacySizeIds,
                (float) $product->new_price
            );
            if (!empty($sizePricingRows) || $request->has('size_pricing') || $request->has('sizes')) {
                $this->syncSizePricing($product, $sizePricingRows);
            }

            $this->syncProductImages($product, $request);

            DB::commit();

            $product->load([
                'category',
                'subcategory',
                'brand',
                'image',
                'images',
                'featureImage',
                'galleryImages',
                'colors',
                'sizes',
                'sizePricings.size:id,sizeName',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $this->formatProduct($product, true),
            ], 201);
        } catch (ValidationException $exception) {
            DB::rollBack();
            throw $exception;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error creating product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update product.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'product_code' => 'sometimes|nullable|string|max:255',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'selling_price' => 'sometimes|required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|integer|in:0,1',
            'topsale' => 'sometimes|integer|in:0,1',
            'feature' => 'sometimes|integer|in:0,1',
            'view_home' => 'nullable|boolean',
            'colors' => 'nullable|array',
            'colors.*' => 'integer|exists:colors,id',
            'sizes' => 'nullable|array',
            'sizes.*' => 'integer|exists:sizes,id',
            'remove_gallery_image_ids' => 'nullable|array',
            'remove_gallery_image_ids.*' => 'integer',
            'image' => 'nullable|image|max:5120',
            'feature_image' => 'nullable|image|max:5120',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'image|max:5120',
            'images' => 'nullable',
            'size_pricing' => 'nullable',
            'youtube_video_url' => 'nullable|string|max:1000',
            'facebook_video_url' => 'nullable|string|max:1000',
        ]);

        $videoPayload = $this->resolveVideoPayload($request, true);

        try {
            DB::beginTransaction();

            $product = Product::findOrFail($id);

            if ($request->has('name')) {
                $product->name = (string) $request->name;
            }
            if ($request->has('category_id')) {
                $product->category_id = (int) $request->category_id;
            }
            if ($this->productColumnExists('subcategory_id') && $request->has('subcategory_id')) {
                $product->subcategory_id = $request->filled('subcategory_id') ? (int) $request->subcategory_id : null;
            }
            if ($this->productColumnExists('brand_id') && $request->has('brand_id')) {
                $product->brand_id = $request->filled('brand_id') ? (int) $request->brand_id : null;
            }
            if ($request->has('product_code')) {
                $product->product_code = $this->resolveUniqueProductCode($request->input('product_code'), (int) $product->id);
            }
            if ($request->has('purchase_price')) {
                $product->purchase_price = (float) $request->purchase_price;
            }
            if ($request->has('selling_price')) {
                $product->new_price = (float) $request->selling_price;
            }
            if ($request->has('old_price')) {
                $product->old_price = $request->filled('old_price') ? (float) $request->old_price : null;
            }
            if ($request->has('stock')) {
                $product->stock = (int) $request->stock;
            }
            if ($request->has('description')) {
                $product->description = $request->description;
            }
            if ($request->has('status')) {
                $product->status = (int) $request->status;
            }
            if ($request->has('topsale')) {
                $product->topsale = (int) $request->topsale;
            }
            if ($request->has('feature')) {
                $product->feature_product = (int) $request->feature;
            }
            if ($this->productColumnExists('view_home') && $request->has('view_home')) {
                $product->view_home = $request->boolean('view_home');
            }
            $this->applyVideoPayload($product, $videoPayload);
            $product->save();

            if ($request->has('colors')) {
                $product->colors()->sync($this->normalizeIdArray($validated['colors'] ?? []));
            }

            $rawSizePricing = $this->parseArrayInput($request, 'size_pricing');
            $legacySizeIds = $this->normalizeIdArray($this->parseArrayInput($request, 'sizes'));
            if ($request->has('size_pricing') || $request->has('sizes')) {
                $sizePricingRows = $this->normalizeSizePricingRows(
                    $rawSizePricing,
                    $legacySizeIds,
                    (float) $product->new_price
                );
                $this->syncSizePricing($product, $sizePricingRows);
            }

            $removeGalleryImageIds = $this->normalizeIdArray($this->parseArrayInput($request, 'remove_gallery_image_ids'));
            if (!empty($removeGalleryImageIds)) {
                $product->images()->whereIn('id', $removeGalleryImageIds)->delete();
            }

            $this->syncProductImages($product, $request);

            DB::commit();

            $product->load([
                'category',
                'subcategory',
                'brand',
                'image',
                'images',
                'featureImage',
                'galleryImages',
                'colors',
                'sizes',
                'sizePricings.size:id,sizeName',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $this->formatProduct($product, true),
            ]);
        } catch (ValidationException $exception) {
            DB::rollBack();
            throw $exception;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error updating product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update product status.
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'status' => 'required|integer|in:0,1',
        ]);

        try {
            Product::whereIn('id', $request->product_ids)
                ->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Product status updated successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete products.
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
        ]);

        try {
            Product::whereIn('id', $request->product_ids)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Products deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get categories, subcategories, and brands for filters.
     */
    public function filters()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => Category::select('id', 'name')->where('status', 1)->get(),
                    'subcategories' => Subcategory::select('id', 'subcategoryName as name', 'category_id')->where('status', 1)->get(),
                    'brands' => Brand::select('id', 'name')->where('status', 1)->get(),
                    'colors' => Color::select('id', 'colorName as name', 'color as color_code')->where('status', 1)->get(),
                    'sizes' => Size::select('id', 'sizeName as name')->where('status', 1)->get(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching filters: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normalize array-like request payloads that may come as JSON strings.
     */
    private function parseArrayInput(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Normalize an ID list to unique positive integers.
     */
    private function normalizeIdArray(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }

            $value = (int) $id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Resolve and validate size pricing rows.
     *
     * @throws ValidationException
     */
    private function normalizeSizePricingRows(array $rawRows, array $legacySizeIds, float $fallbackPrice): array
    {
        $rows = [];
        $seen = [];
        $defaultRowCount = 0;

        foreach ($rawRows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $sizeId = null;
            if (array_key_exists('size_id', $row) && $row['size_id'] !== null && $row['size_id'] !== '') {
                if (!is_numeric($row['size_id'])) {
                    throw ValidationException::withMessages([
                        "size_pricing.{$index}.size_id" => 'Size ID must be numeric.',
                    ]);
                }
                $sizeId = (int) $row['size_id'];
            }

            $sizeName = trim((string) ($row['size_name'] ?? ''));
            if ($sizeId !== null) {
                $size = Size::query()->find($sizeId);
                if (!$size) {
                    throw ValidationException::withMessages([
                        "size_pricing.{$index}.size_id" => 'Selected size does not exist.',
                    ]);
                }
                if ($sizeName === '') {
                    $sizeName = trim((string) ($size->sizeName ?? ''));
                }
            }

            $price = $row['price'] ?? null;
            $isDefault = $this->normalizeBooleanish($row['is_default'] ?? false);
            if (($price === null || $price === '') && $isDefault) {
                $price = $fallbackPrice;
            }
            $isEmptyRow = $sizeName === '' && $sizeId === null && ($price === null || $price === '');
            if ($isEmptyRow) {
                continue;
            }

            if ($sizeName === '') {
                throw ValidationException::withMessages([
                    "size_pricing.{$index}.size_name" => 'Size name is required.',
                ]);
            }

            if (!is_numeric($price) || (float) $price < 0) {
                throw ValidationException::withMessages([
                    "size_pricing.{$index}.price" => 'Size price must be a valid non-negative number.',
                ]);
            }

            $signature = strtolower($sizeName) . '|' . ($sizeId ?? 'custom');
            if (isset($seen[$signature])) {
                throw ValidationException::withMessages([
                    "size_pricing.{$index}.size_name" => 'Duplicate size entries are not allowed.',
                ]);
            }
            $seen[$signature] = true;

            $rows[] = [
                'size_id' => $sizeId,
                'size_name' => $sizeName,
                'price' => round((float) $price, 2),
                'sort_order' => count($rows),
                'is_default' => $isDefault,
            ];

            if ($isDefault) {
                $defaultRowCount++;
            }
        }

        if (!empty($rows)) {
            if ($defaultRowCount > 1) {
                throw ValidationException::withMessages([
                    'size_pricing' => 'Only one size can be marked as default.',
                ]);
            }

            return $this->ensureSingleDefaultSizeRow($rows);
        }

        if (empty($legacySizeIds)) {
            return $rows;
        }

        $sizes = Size::query()->whereIn('id', $legacySizeIds)->get()->keyBy('id');

        $legacyDefaultAssigned = false;
        foreach ($legacySizeIds as $legacySizeId) {
            $size = $sizes->get($legacySizeId);
            if (!$size) {
                throw ValidationException::withMessages([
                    'sizes' => 'One or more selected sizes are invalid.',
                ]);
            }

            $rows[] = [
                'size_id' => $legacySizeId,
                'size_name' => trim((string) ($size->sizeName ?? '')),
                'price' => round($fallbackPrice, 2),
                'sort_order' => count($rows),
                'is_default' => !$legacyDefaultAssigned,
            ];
            $legacyDefaultAssigned = true;
        }

        return $rows;
    }

    private function syncSizePricing(Product $product, array $rows): void
    {
        $product->sizePricings()->delete();

        if (empty($rows)) {
            $product->sizes()->sync([]);
            return;
        }

        $product->sizePricings()->createMany($this->ensureSingleDefaultSizeRow($rows));

        $sizeIds = collect($rows)
            ->pluck('size_id')
            ->filter(fn ($sizeId) => is_numeric($sizeId) && (int) $sizeId > 0)
            ->map(fn ($sizeId) => (int) $sizeId)
            ->unique()
            ->values()
            ->all();

        $product->sizes()->sync($sizeIds);
    }

    private function syncProductImages(Product $product, Request $request): void
    {
        $imageColor = $this->resolveProductImageColor($request);
        $featureImageFiles = $this->collectUploadedImageFiles($request, ['feature_image', 'image']);
        if (count($featureImageFiles) > 1) {
            throw ValidationException::withMessages([
                'feature_image' => 'Only one feature image is allowed.',
            ]);
        }
        $featureImageFile = $featureImageFiles[0] ?? null;

        if ($featureImageFile instanceof UploadedFile) {
            $imagePath = $this->storeUploadedImage($featureImageFile);
            $product->images()->update(['is_feature' => 0]);

            Productimage::create([
                'product_id' => $product->id,
                'image' => $imagePath,
                'image_color' => $imageColor,
                'is_feature' => 1,
                'sort_order' => 0,
            ]);
        }

        $galleryFiles = $this->collectUploadedImageFiles($request, ['gallery_images', 'images']);
        if (!empty($galleryFiles)) {
            $nextSortOrder = (int) ($product->images()->max('sort_order') ?? 0);

            foreach ($galleryFiles as $file) {
                $nextSortOrder++;

                Productimage::create([
                    'product_id' => $product->id,
                    'image' => $this->storeUploadedImage($file),
                    'image_color' => $imageColor,
                    'is_feature' => 0,
                    'sort_order' => $nextSortOrder,
                ]);
            }
        }

        $this->ensureFeatureImageConsistency($product);
    }

    private function resolveVideoPayload(Request $request, bool $updateOnlyWhenPresent = false): array
    {
        $hasYoutubeField = $request->has('youtube_video_url');
        $hasFacebookField = $request->has('facebook_video_url');

        if ($updateOnlyWhenPresent && !$hasYoutubeField && !$hasFacebookField) {
            return [
                'should_update' => false,
                'youtube_video_url' => null,
                'facebook_video_url' => null,
                'legacy_youtube_id' => null,
            ];
        }

        $youtubeVideoUrl = $this->normalizeYoutubeVideoUrl($request->input('youtube_video_url'));
        $facebookVideoUrl = $this->normalizeFacebookVideoUrl($request->input('facebook_video_url'));

        if ($youtubeVideoUrl !== null && $facebookVideoUrl !== null) {
            throw ValidationException::withMessages([
                'youtube_video_url' => 'Please provide either YouTube or Facebook video, not both.',
                'facebook_video_url' => 'Please provide either YouTube or Facebook video, not both.',
            ]);
        }

        return [
            'should_update' => true,
            'youtube_video_url' => $youtubeVideoUrl,
            'facebook_video_url' => $facebookVideoUrl,
            'legacy_youtube_id' => $youtubeVideoUrl !== null
                ? $this->extractYoutubeVideoId($youtubeVideoUrl)
                : null,
        ];
    }

    private function applyVideoPayload(Product $product, array $videoPayload): void
    {
        if (!($videoPayload['should_update'] ?? false)) {
            return;
        }

        if ($this->productColumnExists('youtube_video_url')) {
            $product->youtube_video_url = $videoPayload['youtube_video_url'] ?? null;
        }

        if ($this->productColumnExists('facebook_video_url')) {
            $product->facebook_video_url = $videoPayload['facebook_video_url'] ?? null;
        }

        if ($this->productColumnExists('pro_video')) {
            $product->pro_video = $videoPayload['legacy_youtube_id'] ?? null;
        }
    }

    private function normalizeYoutubeVideoUrl(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $videoId = $this->extractYoutubeVideoId($raw);
        if ($videoId === null) {
            throw ValidationException::withMessages([
                'youtube_video_url' => 'Please provide a valid YouTube URL or video ID.',
            ]);
        }

        return "https://www.youtube.com/watch?v={$videoId}";
    }

    private function normalizeFacebookVideoUrl(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalizedUrl = $this->ensureUrlHasScheme($raw);
        if (!filter_var($normalizedUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'facebook_video_url' => 'Please provide a valid Facebook video URL.',
            ]);
        }

        $parts = parse_url($normalizedUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!$this->isFacebookHost($host)) {
            throw ValidationException::withMessages([
                'facebook_video_url' => 'Only Facebook video URLs are allowed for this field.',
            ]);
        }

        return $normalizedUrl;
    }

    private function extractYoutubeVideoId(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $raw) === 1) {
            return $raw;
        }

        $candidateUrl = $this->ensureUrlHasScheme($raw);
        if (!filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($candidateUrl);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $hostWithoutWww = preg_replace('/^www\./', '', $host);
        $path = (string) ($parts['path'] ?? '');
        $pathSegments = array_values(array_filter(explode('/', trim($path, '/')), fn ($segment) => $segment !== ''));

        $videoId = null;

        if ($hostWithoutWww === 'youtu.be') {
            $videoId = $pathSegments[0] ?? null;
        } elseif (
            $hostWithoutWww === 'youtube.com'
            || str_ends_with($hostWithoutWww, '.youtube.com')
            || $hostWithoutWww === 'youtube-nocookie.com'
            || str_ends_with($hostWithoutWww, '.youtube-nocookie.com')
        ) {
            if (($pathSegments[0] ?? '') === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = isset($query['v']) ? (string) $query['v'] : null;
            } elseif (($pathSegments[0] ?? '') === 'embed') {
                $videoId = $pathSegments[1] ?? null;
            } elseif (($pathSegments[0] ?? '') === 'shorts') {
                $videoId = $pathSegments[1] ?? null;
            }
        }

        if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }

        return $videoId;
    }

    private function ensureUrlHasScheme(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return 'https://' . ltrim($normalized, '/');
    }

    private function isFacebookHost(string $host): bool
    {
        $normalizedHost = strtolower(preg_replace('/^www\./', '', trim($host)));
        if ($normalizedHost === '') {
            return false;
        }

        return $normalizedHost === 'facebook.com'
            || str_ends_with($normalizedHost, '.facebook.com')
            || $normalizedHost === 'fb.watch';
    }

    /**
     * @return UploadedFile[]
     */
    private function collectUploadedImageFiles(Request $request, array $keys): array
    {
        $files = [];

        foreach ($keys as $key) {
            if (!$request->hasFile($key)) {
                continue;
            }

            $input = $request->file($key);
            if ($input instanceof UploadedFile) {
                $files[] = $input;
                continue;
            }

            if (!is_array($input)) {
                continue;
            }

            foreach ($input as $item) {
                if ($item instanceof UploadedFile) {
                    $files[] = $item;
                }
            }
        }

        return $files;
    }

    private function storeUploadedImage(UploadedFile $file): string
    {
        $directory = public_path('uploads/products');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $name = now()->timestamp . '_' . Str::random(8) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
        $file->move($directory, $name);

        return 'uploads/products/' . $name;
    }

    /**
     * Guarantees exactly one default row when rows exist.
     */
    private function ensureSingleDefaultSizeRow(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $defaultIndexes = [];
        foreach ($rows as $index => $row) {
            if ($this->normalizeBooleanish($row['is_default'] ?? false)) {
                $defaultIndexes[] = $index;
            }
        }

        if (count($defaultIndexes) > 1) {
            throw ValidationException::withMessages([
                'size_pricing' => 'Only one size can be marked as default.',
            ]);
        }

        if (count($defaultIndexes) === 0) {
            $rows[0]['is_default'] = true;
            for ($i = 1; $i < count($rows); $i++) {
                $rows[$i]['is_default'] = false;
            }
            return $rows;
        }

        $keepIndex = $defaultIndexes[0];
        foreach ($rows as $index => $row) {
            $rows[$index]['is_default'] = $index === $keepIndex;
        }

        return $rows;
    }

    private function normalizeBooleanish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function resolveProductImageColor(Request $request): string
    {
        $color = trim((string) $request->input('image_color', ''));
        if ($color === '') {
            return 'default';
        }

        return Str::limit($color, 255, '');
    }

    private function resolveUniqueProductCode(mixed $rawCode, ?int $ignoreProductId = null): string
    {
        $normalized = trim((string) ($rawCode ?? ''));

        if ($normalized === '') {
            return $this->generateUniqueProductCode($ignoreProductId);
        }

        $candidate = Str::limit($normalized, 255, '');
        if ($candidate === '') {
            return $this->generateUniqueProductCode($ignoreProductId);
        }

        if (!$this->productCodeExists($candidate, $ignoreProductId)) {
            return $candidate;
        }

        $base = Str::limit($candidate, 245, '');
        if ($base === '') {
            $base = 'PRD';
        }

        $suffix = 2;
        do {
            $nextCandidate = Str::limit($base . '-' . $suffix, 255, '');
            $suffix++;
        } while ($this->productCodeExists($nextCandidate, $ignoreProductId));

        return $nextCandidate;
    }

    private function generateUniqueProductCode(?int $ignoreProductId = null): string
    {
        $attempt = 0;
        do {
            $candidate = (string) random_int(100000, 999999);
            $attempt++;
            if ($attempt > 1000) {
                throw ValidationException::withMessages([
                    'product_code' => 'Unable to generate a unique 6-digit SKU. Please try again.',
                ]);
            }
        } while ($this->productCodeExists($candidate, $ignoreProductId));

        return $candidate;
    }

    private function productCodeExists(string $code, ?int $ignoreProductId = null): bool
    {
        $query = Product::query()->where('product_code', $code);

        if ($ignoreProductId !== null) {
            $query->where('id', '!=', $ignoreProductId);
        }

        return $query->exists();
    }

    private function productColumnExists(string $column): bool
    {
        if (array_key_exists($column, $this->productColumnCache)) {
            return $this->productColumnCache[$column];
        }

        $exists = Schema::hasColumn('products', $column);
        $this->productColumnCache[$column] = $exists;

        return $exists;
    }

    private function ensureFeatureImageConsistency(Product $product): void
    {
        $images = $product->images()->orderByDesc('is_feature')->orderBy('sort_order')->orderBy('id')->get();
        if ($images->isEmpty()) {
            return;
        }

        $hasFeature = $images->contains(fn ($image) => (bool) $image->is_feature === true);
        if ($hasFeature) {
            $featureImageId = $images->firstWhere('is_feature', true)?->id;
            if ($featureImageId) {
                $product->images()
                    ->where('id', '!=', $featureImageId)
                    ->where('is_feature', 1)
                    ->update(['is_feature' => 0]);
            }
            return;
        }

        $firstImage = $images->first();
        if ($firstImage) {
            $firstImage->is_feature = 1;
            $firstImage->sort_order = 0;
            $firstImage->save();
        }
    }

    /**
     * Format product for response.
     */
    private function formatProduct(Product $product, bool $detailed = false): array
    {
        $product->loadMissing([
            'category',
            'subcategory',
            'brand',
            'image',
            'images',
            'featureImage',
            'galleryImages',
            'colors',
            'sizes',
            'sizePricings.size:id,sizeName',
        ]);

        $featureImagePath = $product->featureImage?->image ?? $product->image?->image;

        $sizePricingRows = $product->sizePricings->map(function ($row) use ($product) {
            $sizeName = trim((string) ($row->size_name ?? ''));
            if ($sizeName === '') {
                $sizeName = trim((string) ($row->size?->sizeName ?? ''));
            }

            return [
                'id' => $row->id,
                'size_id' => $row->size_id,
                'size_name' => $sizeName,
                'price' => $row->price !== null ? (float) $row->price : (float) ($product->new_price ?? 0),
                'is_default' => (bool) ($row->is_default ?? false),
            ];
        })->values();

        $youtubeVideoUrl = null;
        if ($this->productColumnExists('youtube_video_url')) {
            $value = trim((string) ($product->youtube_video_url ?? ''));
            $youtubeVideoUrl = $value !== '' ? $value : null;
        }

        $facebookVideoUrl = null;
        if ($this->productColumnExists('facebook_video_url')) {
            $value = trim((string) ($product->facebook_video_url ?? ''));
            $facebookVideoUrl = $value !== '' ? $value : null;
        }

        $legacyYoutubeId = null;
        if ($this->productColumnExists('pro_video')) {
            $legacyRaw = trim((string) ($product->pro_video ?? ''));
            if ($legacyRaw !== '') {
                $legacyYoutubeId = $this->extractYoutubeVideoId($legacyRaw);
            }
        }

        $resolvedVideoType = null;
        $resolvedVideoUrl = null;
        if ($youtubeVideoUrl !== null) {
            $resolvedVideoType = 'youtube';
            $resolvedVideoUrl = $youtubeVideoUrl;
        } elseif ($facebookVideoUrl !== null) {
            $resolvedVideoType = 'facebook';
            $resolvedVideoUrl = $facebookVideoUrl;
        } elseif ($legacyYoutubeId !== null) {
            $resolvedVideoType = 'youtube';
            $resolvedVideoUrl = "https://www.youtube.com/watch?v={$legacyYoutubeId}";
        }

        $formatted = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->product_code,
            'purchase_price' => (float) $product->purchase_price,
            'selling_price' => (float) $product->new_price,
            'old_price' => $product->old_price !== null ? (float) $product->old_price : null,
            'stock' => (int) $product->stock,
            'status' => (int) $product->status,
            'topsale' => (int) ($product->topsale ?? 0),
            'feature' => (int) ($product->feature_product ?? 0),
            'view_home' => (bool) $product->view_home,
            'image' => $featureImagePath,
            'feature_image' => $featureImagePath,
            'gallery_images' => $product->galleryImages->map(function ($img) {
                return [
                    'id' => $img->id,
                    'image' => $img->image,
                ];
            })->values(),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'subcategory' => $product->subcategory ? [
                'id' => $product->subcategory->id,
                'name' => $product->subcategory->subcategoryName,
            ] : null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
            ] : null,
            'colors' => $product->colors->map(function ($color) {
                return [
                    'id' => $color->id,
                    'name' => $color->colorName,
                    'color_code' => $color->color,
                ];
            })->values(),
            'sizes' => $product->sizes->map(function ($size) {
                return [
                    'id' => $size->id,
                    'name' => $size->sizeName,
                ];
            })->values(),
            'size_pricing' => $sizePricingRows,
            'youtube_video_url' => $youtubeVideoUrl,
            'facebook_video_url' => $facebookVideoUrl,
            'pro_video' => $legacyYoutubeId,
            'video_type' => $resolvedVideoType,
            'video_url' => $resolvedVideoUrl,
        ];

        if ($detailed) {
            $formatted['description'] = $product->description;
            $formatted['images'] = $product->images->map(function ($img) {
                return $img->image;
            })->values();
            $formatted['created_at'] = optional($product->created_at)->format('Y-m-d H:i:s');
            $formatted['updated_at'] = optional($product->updated_at)->format('Y-m-d H:i:s');
        }

        return $formatted;
    }
}
