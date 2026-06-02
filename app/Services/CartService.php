<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Productsize;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function getCart(string $cartId, ?User $user = null): ?Cart
    {
        $query = Cart::with(['items.product.image']);

        if ($user) {
            // Find user's active cart or merge if guest cart exists
            $userCart = Cart::where('user_id', $user->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($cartId && $cartId !== ($userCart->id ?? null)) {
                // If guest cart exists and is different, merge it
                $guestCart = Cart::where('id', $cartId)->whereNull('user_id')->first();
                if ($guestCart) {
                    if (!$userCart) {
                        // Assign guest cart to user
                        $guestCart->update(['user_id' => $user->id]);
                        return $guestCart->load('items.product.image');
                    } else {
                        // Merge guest items to user cart
                        $this->mergeCarts($guestCart, $userCart);
                    }
                }
            }
            return $userCart ? $userCart->load('items.product.image') : $this->createCart($user);
        }

        if ($cartId) {
            $cart = Cart::with(['items.product.image'])->find($cartId);
            return $cart ?: $this->createCart();
        }

        return $this->createCart();
    }

    public function createCart(?User $user = null): Cart
    {
        return Cart::create([
            'user_id' => $user->id ?? null,
            'status' => 'active',
        ]);
    }

    public function addItem(string $cartId, int $productId, int $qty, array $options = []): Cart
    {
        $cart = Cart::findOrFail($cartId);
        $product = Product::findOrFail($productId);
        $productName = trim((string) ($product->name ?? 'Product'));
        $productImage = $this->resolveInternalProductImage($product);
        $normalizedOptions = $this->normalizeInternalOptions($options, $product);
        [$normalizedOptions, $resolvedPrice] = $this->validateVariantAndResolvePrice($product, $normalizedOptions);

        $existingItem = $cart->items()
            ->where('product_id', $productId)
            // Ideally should check options equality too, simplified for now
            // Need robust option comparison for size/color
            ->get()
            ->first(function ($item) use ($normalizedOptions) {
                // Simple comparison of arrays
                return empty(array_diff_assoc($item->options ?? [], $normalizedOptions)) &&
                       empty(array_diff_assoc($normalizedOptions, $item->options ?? []));
            });


        if ($existingItem) {
            $existingItem->increment('quantity', $qty);
            if ((float) $existingItem->price !== (float) $resolvedPrice) {
                $existingItem->price = $resolvedPrice;
            }
            if (trim((string) ($existingItem->product_name ?? '')) === '' && $productName !== '') {
                $existingItem->product_name = $productName;
            }
            if (trim((string) ($existingItem->product_image ?? '')) === '' && $productImage !== '') {
                $existingItem->product_image = $productImage;
            }
            if ($existingItem->isDirty(['price', 'product_name', 'product_image'])) {
                $existingItem->save();
            }
            $this->syncExistingItemOptions($existingItem, $normalizedOptions);
        } else {
            $cart->items()->create([
                'product_id' => $productId,
                'product_name' => $productName,
                'product_image' => $productImage,
                'quantity' => $qty,
                'price' => $resolvedPrice,
                'options' => $normalizedOptions,
            ]);
        }

        return $cart->load('items.product.image');
    }

    public function addExternalItem(string $cartId, array $payload): Cart
    {
        $cart = Cart::findOrFail($cartId);

        $externalId = (string) ($payload['external_product_id'] ?? '');
        $name = $payload['product_name'] ?? 'External Product';
        $image = $payload['product_image'] ?? null;
        $qty = (int) ($payload['quantity'] ?? 1);
        $price = (float) ($payload['price'] ?? 0);
        $options = $this->normalizeExternalOptions($payload['options'] ?? [], $payload);

        $existingItem = $cart->items()
            ->where('external_product_id', $externalId)
            ->whereNull('product_id')
            ->get()
            ->first(function ($item) use ($options) {
                return empty(array_diff_assoc($item->options ?? [], $options)) &&
                       empty(array_diff_assoc($options, $item->options ?? []));
            });

        if ($existingItem) {
            $existingItem->increment('quantity', $qty);
            $this->syncExistingItemOptions($existingItem, $options);
        } else {
            $cart->items()->create([
                'external_product_id' => $externalId,
                'product_name' => $name,
                'product_image' => $image,
                'quantity' => $qty,
                'price' => $price,
                'options' => $options,
            ]);
        }

        return $cart->load('items.product.image');
    }

    public function updateItem(string $cartId, int $itemId, int $qty): Cart
    {
        $cart = Cart::findOrFail($cartId);
        $item = $cart->items()->where('id', $itemId)->firstOrFail();

        if ($qty <= 0) {
            $item->delete();
        } else {
            $item->update(['quantity' => $qty]);
        }

        return $cart->load('items.product.image');
    }

    public function removeItem(string $cartId, int $itemId): Cart
    {
        $cart = Cart::findOrFail($cartId);
        $cart->items()->where('id', $itemId)->delete();
        return $cart->load('items.product.image');
    }

    public function clearCart(string $cartId): void
    {
        $cart = Cart::findOrFail($cartId);
        $cart->items()->delete();
    }

    protected function mergeCarts(Cart $guestCart, Cart $userCart): void
    {
        DB::transaction(function () use ($guestCart, $userCart) {
            foreach ($guestCart->items as $item) {
                $existingItem = $userCart->items()
                     ->where('product_id', $item->product_id)
                     ->get()
                     ->first(function ($uItem) use ($item) {
                         return empty(array_diff_assoc($uItem->options ?? [], $item->options ?? [])) &&
                                empty(array_diff_assoc($item->options ?? [], $uItem->options ?? []));
                     });

                if ($existingItem) {
                    $existingItem->increment('quantity', $item->quantity);
                } else {
                    $item->update(['cart_id' => $userCart->id]);
                }
            }
            $guestCart->delete();
        });
    }

    private function normalizeInternalOptions(array $options, Product $product): array
    {
        $normalized = $options;
        $sku = trim((string) (
            $normalized['sku']
            ?? $normalized['product_sku']
            ?? $product->sku
            ?? $product->product_code
            ?? ''
        ));

        if ($sku !== '') {
            $normalized['sku'] = $sku;
            $normalized['product_sku'] = $sku;
        }

        foreach ([
            'variation_id',
            'variation_sku',
            'selected_attributes',
            'wholesale_price_snapshot',
        ] as $key) {
            if (!array_key_exists($key, $normalized) && array_key_exists($key, $payload)) {
                $normalized[$key] = $payload[$key];
            }
        }

        if (!array_key_exists('product_size', $normalized) && array_key_exists('size', $normalized)) {
            $normalized['product_size'] = $normalized['size'];
        }
        if (!array_key_exists('product_color', $normalized) && array_key_exists('color', $normalized)) {
            $normalized['product_color'] = $normalized['color'];
        }
        if (!array_key_exists('product_size_id', $normalized) && array_key_exists('size_id', $normalized)) {
            $normalized['product_size_id'] = $normalized['size_id'];
        }
        if (!array_key_exists('product_color_id', $normalized) && array_key_exists('color_id', $normalized)) {
            $normalized['product_color_id'] = $normalized['color_id'];
        }

        return $normalized;
    }

    private function normalizeExternalOptions(array $options, array $payload): array
    {
        $normalized = $options;
        $sku = trim((string) (
            $normalized['sku']
            ?? $normalized['product_sku']
            ?? ($payload['sku'] ?? null)
            ?? ($payload['external_product_id'] ?? null)
            ?? ''
        ));

        if ($sku !== '') {
            $normalized['sku'] = $sku;
            $normalized['product_sku'] = $sku;
        }

        if (!array_key_exists('product_size', $normalized) && array_key_exists('size', $normalized)) {
            $normalized['product_size'] = $normalized['size'];
        }
        if (!array_key_exists('product_color', $normalized) && array_key_exists('color', $normalized)) {
            $normalized['product_color'] = $normalized['color'];
        }
        if (!array_key_exists('product_size_id', $normalized) && array_key_exists('size_id', $normalized)) {
            $normalized['product_size_id'] = $normalized['size_id'];
        }
        if (!array_key_exists('product_color_id', $normalized) && array_key_exists('color_id', $normalized)) {
            $normalized['product_color_id'] = $normalized['color_id'];
        }

        return $normalized;
    }

    /**
     * Validate selected size and price against product variant pricing.
     *
     * @return array{0: array, 1: float}
     *
     * @throws ValidationException
     */
    private function validateVariantAndResolvePrice(Product $product, array $options): array
    {
        $selectedVariant = $this->resolveSelectedSizeVariant($product, $options);
        $incomingPrice = $options['price'] ?? null;

        if ($selectedVariant) {
            $resolvedPrice = round((float) ($selectedVariant->price ?? $product->new_price ?? 0), 2);
            if ($incomingPrice !== null && $incomingPrice !== '' && $this->pricesMismatch($incomingPrice, $resolvedPrice)) {
                throw ValidationException::withMessages([
                    'options.price' => 'Selected size price is invalid.',
                ]);
            }

            $resolvedSizeName = trim((string) (
                $selectedVariant->size_name
                ?: $selectedVariant->size?->sizeName
                ?: ($options['product_size'] ?? null)
                ?: ($options['size'] ?? null)
                ?: ''
            ));

            $options['product_size_id'] = (int) $selectedVariant->id;
            $options['size_variant_id'] = (int) $selectedVariant->id;
            $options['size_id'] = (int) $selectedVariant->id; // legacy compatibility: historically this was variant row ID

            if ($selectedVariant->size_id) {
                $options['catalog_size_id'] = (int) $selectedVariant->size_id;
            }

            if ($resolvedSizeName !== '') {
                $options['product_size'] = $resolvedSizeName;
                $options['size'] = $resolvedSizeName;
            }

            $options['price'] = $resolvedPrice;
            $options['selected_size_price'] = $resolvedPrice;

            return [$options, $resolvedPrice];
        }

        $basePrice = round((float) ($product->new_price ?? 0), 2);
        if ($incomingPrice !== null && $incomingPrice !== '' && $this->pricesMismatch($incomingPrice, $basePrice)) {
            throw ValidationException::withMessages([
                'options.price' => 'Submitted price does not match the current product price.',
            ]);
        }

        $options['price'] = $basePrice;

        return [$options, $basePrice];
    }

    /**
     * Resolve a selected variant from options.
     *
     * @throws ValidationException
     */
    private function resolveSelectedSizeVariant(Product $product, array $options): ?Productsize
    {
        $variants = $product->sizePricings()
            ->with('size:id,sizeName')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $variantId = $this->extractNumericOption($options, ['product_size_id', 'size_variant_id']);
        $sizeIdValue = $this->extractNumericOption($options, ['size_id', 'catalog_size_id']);
        $sizeNameValue = trim((string) ($options['product_size'] ?? $options['size'] ?? ''));

        $sizeHintProvided = $variantId !== null || $sizeIdValue !== null || $sizeNameValue !== '';

        if ($variantId !== null) {
            $matched = $variants->firstWhere('id', $variantId);
            if ($matched) {
                return $matched;
            }
        }

        if ($sizeIdValue !== null) {
            $matchedByVariantId = $variants->firstWhere('id', $sizeIdValue);
            if ($matchedByVariantId) {
                return $matchedByVariantId;
            }

            $matchedByCatalogSize = $variants->first(function (Productsize $variant) use ($sizeIdValue) {
                return (int) ($variant->size_id ?? 0) === $sizeIdValue;
            });
            if ($matchedByCatalogSize) {
                return $matchedByCatalogSize;
            }
        }

        if ($sizeNameValue !== '') {
            $matchedByName = $variants->first(function (Productsize $variant) use ($sizeNameValue) {
                $variantName = trim((string) (
                    $variant->size_name
                    ?: $variant->size?->sizeName
                    ?: ''
                ));

                return $variantName !== '' && Str::lower($variantName) === Str::lower($sizeNameValue);
            });
            if ($matchedByName) {
                return $matchedByName;
            }
        }

        if ($sizeHintProvided) {
            throw ValidationException::withMessages([
                'options.product_size_id' => 'Selected size is not valid for this product.',
            ]);
        }

        // Backward-compatible default: if size variants exist and nothing is provided, use first variant.
        return $variants->first();
    }

    private function extractNumericOption(array $options, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            $value = $options[$key];
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            $intValue = (int) $value;
            if ($intValue > 0) {
                return $intValue;
            }
        }

        return null;
    }

    private function pricesMismatch(mixed $incomingPrice, float $expectedPrice): bool
    {
        if (!is_numeric($incomingPrice)) {
            return true;
        }

        $incoming = round((float) $incomingPrice, 2);
        $expected = round($expectedPrice, 2);

        return abs($incoming - $expected) > 0.009;
    }

    private function syncExistingItemOptions(CartItem $item, array $normalizedOptions): void
    {
        $existingOptions = is_array($item->options ?? null) ? $item->options : [];
        $updatedOptions = $existingOptions;

        $sku = trim((string) (
            $normalizedOptions['sku']
            ?? $normalizedOptions['product_sku']
            ?? ''
        ));

        if ($sku !== '') {
            if (trim((string) ($updatedOptions['sku'] ?? '')) === '') {
                $updatedOptions['sku'] = $sku;
            }
            if (trim((string) ($updatedOptions['product_sku'] ?? '')) === '') {
                $updatedOptions['product_sku'] = $sku;
            }
        }

        foreach ([
            'product_size',
            'product_color',
            'product_size_id',
            'product_color_id',
            'size',
            'color',
            'size_id',
            'color_id',
            'size_variant_id',
            'catalog_size_id',
            'variation_id',
            'variation_sku',
            'selected_attributes',
            'wholesale_price_snapshot',
            'price',
            'selected_size_price',
        ] as $key) {
            if (array_key_exists($key, $normalizedOptions) && ($updatedOptions[$key] ?? null) !== $normalizedOptions[$key]) {
                $updatedOptions[$key] = $normalizedOptions[$key];
            }
        }

        if ($updatedOptions !== $existingOptions) {
            $item->options = $updatedOptions;
            $item->save();
        }
    }

    private function resolveInternalProductImage(Product $product): string
    {
        $featureImage = $product->image()->value('image');
        if (trim((string) $featureImage) !== '') {
            return trim((string) $featureImage);
        }

        $candidates = [
            $product->feature_image ?? null,
            $product->thumbnail ?? null,
            $product->image ?? null,
        ];

        foreach ($candidates as $value) {
            $imagePath = trim((string) $value);
            if ($imagePath !== '') {
                return $imagePath;
            }
        }

        return '';
    }
}
