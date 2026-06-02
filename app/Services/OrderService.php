<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Productsize;
use App\Models\GeneralSetting;
use App\Models\ShippingCharge;
use App\Services\PanelOrderSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderService
{
    public function __construct(
        protected CartService $cartService,
        protected OrderStatusService $orderStatusService,
        protected PanelOrderSyncService $panelOrderSyncService
    )
    {
    }

    public function createOrder(array $data, string $cartId, ?string $ipAddress = null): Order
    {
        $this->ensureOrdersTableExists();
        $this->orderStatusService->ensureDefaultStatuses();
        $newOrderStatusId = $this->orderStatusService->resolveStatusId('new-order') ?: 1;

        $result = DB::transaction(function () use ($data, $cartId, $ipAddress, $newOrderStatusId) {
            $normalizedIpAddress = $this->normalizeIpAddress($ipAddress);
            $cart = $this->cartService->getCart($cartId);

            if (!$cart || $cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Cart is empty.'],
                ]);
            }

            // 1. Validate cart items (size + price) and calculate subtotal
            $validatedLines = [];
            $subtotal = 0.0;
            foreach ($cart->items as $item) {
                $options = is_array($item->options ?? null) ? $item->options : [];
                $product = null;
                $resolvedSalePrice = round((float) $item->price, 2);

                if ($item->product_id) {
                    $product = Product::query()->find($item->product_id);
                    if (!$product) {
                        throw ValidationException::withMessages([
                            'cart' => ['One or more products in your cart are no longer available.'],
                        ]);
                    }

                    [$options, $resolvedSalePrice] = $this->validateOrderItemVariantPricing(
                        $product,
                        $options,
                        (float) $item->price
                    );
                } elseif (!is_numeric($item->price) || (float) $item->price < 0) {
                    throw ValidationException::withMessages([
                        'cart' => ['Invalid cart item price.'],
                    ]);
                }

                $quantity = max(1, (int) $item->quantity);
                $lineTotal = $resolvedSalePrice * $quantity;
                $subtotal += $lineTotal;

                $validatedLines[] = [
                    'item' => $item,
                    'product' => $product,
                    'options' => $options,
                    'sale_price' => $resolvedSalePrice,
                    'quantity' => $quantity,
                ];
            }

            $discount = 0; // consistent with basic port for now

            $areaId = $data['area'] ?? null;
            $hasExternalItems = $cart->items->contains(fn ($item) => trim((string) ($item->external_product_id ?? '')) !== '');
            $resolvedShipping = $this->resolveCheckoutShipping($data, $areaId, $hasExternalItems);
            $areaShippingFee = $resolvedShipping['amount'];
            $courierCharge = $this->resolveCourierCharge();
            $shippingFee = $areaShippingFee + $courierCharge;
            
            $total = ($subtotal + $shippingFee) - $discount;

            // 2. Handle Customer
            $customer = $this->resolveCustomer($data, $normalizedIpAddress);

            // 3. Create Order
            $order = Order::create([
                'invoice_id' => (string) rand(11111, 99999),
                'amount' => (int) $total,
                'discount' => (int) $discount,
                'shipping_charge' => (int) $shippingFee,
                'customer_id' => $customer->id,
                'order_status' => (string) $newOrderStatusId,
                'ip_address' => $normalizedIpAddress,
                'district' => is_numeric($data['district'] ?? null) ? (int) $data['district'] : null,
            ]);

            // 4. Create Order Details
            foreach ($validatedLines as $line) {
                $item = $line['item'];
                $product = $line['product'];
                $options = $line['options'];
                $salePrice = (float) $line['sale_price'];
                $quantity = (int) $line['quantity'];

                // Stock management
                if ($product) {
                    if ((int) $product->stock < $quantity) {
                        throw ValidationException::withMessages([
                            'cart' => ["Insufficient stock for {$product->name}."],
                        ]);
                    }

                    $product->decrement('stock', $quantity);
                }

                $productSku = trim((string) (
                    $options['sku']
                    ?? $options['product_sku']
                    ?? $product?->sku
                    ?? $product?->product_code
                    ?? $item->external_product_id
                    ?? ''
                ));

                if ($productSku === '') {
                    $productSku = null;
                }

                $productSizeId = $options['product_size_id'] ?? $options['size_id'] ?? null;
                $productColorId = $options['product_color_id'] ?? $options['color_id'] ?? null;
                $productSize = $options['product_size'] ?? $options['size'] ?? null;
                $productColor = $options['product_color'] ?? $options['color'] ?? null;
                $selectedAttributes = is_array($options['selected_attributes'] ?? null)
                    ? $options['selected_attributes']
                    : [];
                $attributeSummary = $this->summarizeSelectedAttributes($selectedAttributes);
                if (!$productSize && $attributeSummary !== '') {
                    $productSize = $attributeSummary;
                }
                $panelProductId = $this->toPositiveInt($options['panel_product_id'] ?? null);
                $panelVariantId = $this->toPositiveInt($options['panel_variant_id'] ?? null);
                $panelSellerProductId = $this->toPositiveInt($options['panel_seller_product_id'] ?? null);

                OrderDetails::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id ?? 0,
                    'panel_product_id' => $panelProductId,
                    'panel_variant_id' => $panelVariantId,
                    'panel_seller_product_id' => $panelSellerProductId,
                    'product_name' => $item->product_name ?? $product?->name ?? $item->product?->name ?? 'Unknown',
                    'purchase_price' => $product?->purchase_price ?? $item->product?->purchase_price ?? 0,
                    'sale_price' => (int) round($salePrice),
                    'qty' => $quantity,
                    'image' => $item->product_image ?? $item->product?->image?->image ?? 'default.png',
                    'product_size_id' => is_numeric($productSizeId) ? (int) $productSizeId : null,
                    'product_color_id' => is_numeric($productColorId) ? (int) $productColorId : null,
                    'product_size' => $productSize,
                    'product_color' => $productColor,
                    'product_sku' => $productSku,
                ]);
            }

            // 5. Create Payment
            Payment::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'payment_method' => $data['payment_method'] ?? 'cod',
                'amount' => $total,
                'payment_status' => 'pending',
            ]);

            // 6. Create Shipping Info
            Shipping::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'address' => $data['address'],
                'area' => $resolvedShipping['name'],
                'ip_address' => $normalizedIpAddress,
                // Add other fields as needed
            ]);

            // 7. Clear Cart
            $this->cartService->clearCart($cart->id);
            $cart->update(['status' => 'completed']);

            return [
                'order' => $order,
                'validatedLines' => $validatedLines,
                'checkoutData' => $data,
            ];
        });

        try {
            return $this->panelOrderSyncService->sync(
                $result['order'],
                $result['validatedLines'],
                $result['checkoutData']
            );
        } catch (Throwable $exception) {
            report($exception);
            $result['order']->update([
                'panel_sync_status' => 'failed',
                'panel_sync_error' => mb_substr((string) $exception->getMessage(), 0, 3000),
                'panel_synced_at' => null,
            ]);
            return $result['order']->fresh();
        }
    }

    /**
     * Safety guard for environments where migrations were not fully applied.
     */
    protected function ensureOrdersTableExists(): void
    {
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->increments('id');
            $table->string('ip_address', 255);
            $table->string('invoice_id', 55);
            $table->integer('amount');
            $table->integer('discount');
            $table->integer('shipping_charge');
            $table->integer('customer_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('district')->nullable();
            $table->string('order_status', 55);
            $table->text('note')->nullable();
            $table->text('admin_note')->nullable();
            $table->tinyInteger('is_complete_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Validate size + price for internal products before order placement.
     *
     * @return array{0: array, 1: float}
     */
    protected function validateOrderItemVariantPricing(Product $product, array $options, float $submittedPrice): array
    {
        $variants = $product->sizePricings()
            ->with('size:id,sizeName')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            $expectedPrice = round((float) ($product->new_price ?? 0), 2);
            if ($this->pricesMismatch($submittedPrice, $expectedPrice)) {
                throw ValidationException::withMessages([
                    'items' => ["Price mismatch for {$product->name}."],
                ]);
            }

            $options['price'] = $expectedPrice;

            return [$options, $expectedPrice];
        }

        $selectedVariant = $this->resolveOrderSizeVariant($variants, $options);
        if (!$selectedVariant) {
            throw ValidationException::withMessages([
                'items' => ["Invalid size selected for {$product->name}."],
            ]);
        }

        $expectedPrice = round((float) ($selectedVariant->price ?? $product->new_price ?? 0), 2);
        if ($this->pricesMismatch($submittedPrice, $expectedPrice)) {
            throw ValidationException::withMessages([
                'items' => ["Price mismatch for {$product->name} and selected size."],
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
        $options['size_id'] = (int) $selectedVariant->id;
        if ($selectedVariant->size_id) {
            $options['catalog_size_id'] = (int) $selectedVariant->size_id;
        }

        if ($resolvedSizeName !== '') {
            $options['product_size'] = $resolvedSizeName;
            $options['size'] = $resolvedSizeName;
        }

        $options['price'] = $expectedPrice;
        $options['selected_size_price'] = $expectedPrice;

        return [$options, $expectedPrice];
    }

    protected function resolveOrderSizeVariant(Collection $variants, array $options): ?Productsize
    {
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
            return null;
        }

        return $variants->first();
    }

    protected function extractNumericOption(array $options, array $keys): ?int
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

    protected function pricesMismatch(float $incomingPrice, float $expectedPrice): bool
    {
        return abs(round($incomingPrice, 2) - round($expectedPrice, 2)) > 0.009;
    }

    protected function resolveCustomer(array $data, ?string $ipAddress): Customer
    {
        // If user logged in (via Sanctum), use that?
        // But request data might have name/phone different from user?
        // Usually checkout creates/updates customer profile.
        
        $customer = Customer::where('phone', $data['phone'])->first();
        if (!$customer) {
            $customer = Customer::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name'] . '-' . rand(1000,9999)),
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'address' => $data['address'],
                'district' => $data['district'] ?? null,
                'password' => bcrypt(rand(111111, 999999)),
                'ip_address' => $ipAddress,
                'verify' => 1,
                'status' => 'active',
            ]);
        }
        return $customer;
    }

    protected function resolveCourierCharge(): int
    {
        $setting = GeneralSetting::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if (!$setting) {
            $setting = GeneralSetting::query()->orderByDesc('id')->first();
        }

        return max(0, (int) ($setting->courier_charge ?? 0));
    }

    protected function resolveCheckoutShipping(array $data, mixed $areaId, bool $hasExternalItems): array
    {
        $shippingId = $this->toPositiveInt($areaId);
        if (!$shippingId) {
            throw ValidationException::withMessages([
                'area' => ['Please select a delivery area.'],
            ]);
        }

        if (!$hasExternalItems) {
            $shippingCharge = ShippingCharge::query()->find($shippingId);
            if (!$shippingCharge) {
                throw ValidationException::withMessages([
                    'area' => ['Selected delivery area is not valid.'],
                ]);
            }

            return [
                'id' => (int) $shippingCharge->id,
                'name' => trim((string) ($shippingCharge->name ?? 'Delivery Charge')),
                'amount' => max(0, (int) ($shippingCharge->amount ?? 0)),
                'source' => 'local',
            ];
        }

        $districtId = $this->toPositiveInt($data['district'] ?? null);
        if (!$districtId) {
            throw ValidationException::withMessages([
                'district' => ['Please select your district.'],
            ]);
        }

        $baseUrl = rtrim((string) config('services.api.base_url', ''), '/');
        $sellerCode = trim((string) config('services.panel.seller_code', ''));
        $userDomain = trim((string) config('services.panel.user_domain', ''));
        if ($baseUrl === '' || $sellerCode === '' || $userDomain === '') {
            throw ValidationException::withMessages([
                'area' => ['External shipping API is not configured.'],
            ]);
        }

        $response = Http::timeout((int) config('services.api.timeout', 20))
            ->connectTimeout((int) config('services.api.connect_timeout', 5))
            ->acceptJson()
            ->get($baseUrl . '/api/v1/product/public/shipping-charge', [
                'sellerCode' => $sellerCode,
                'userDomain' => $userDomain,
                'districtId' => $districtId,
            ]);

        if (!$response->successful()) {
            throw ValidationException::withMessages([
                'area' => ['Unable to load external delivery charge right now.'],
            ]);
        }

        $zones = data_get($response->json(), 'data.zones', []);
        if (!is_array($zones) || count($zones) === 0) {
            $row = data_get($response->json(), 'data', []);
            $zones = is_array($row) ? [$row] : [];
        }

        foreach ($zones as $zone) {
            if ((int) data_get($zone, 'id') !== $shippingId) {
                continue;
            }

            $name = trim((string) data_get($zone, 'name', data_get($zone, 'zoneName', 'Delivery Charge')));
            return [
                'id' => $shippingId,
                'name' => $name !== '' ? $name : 'Delivery Charge',
                'amount' => max(0, (int) round((float) data_get($zone, 'amount', 0))),
                'source' => 'external',
            ];
        }

        throw ValidationException::withMessages([
            'area' => ['Selected delivery area is not available for this district.'],
        ]);
    }

    protected function normalizeIpAddress(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '::ffff:')) {
            $normalized = substr($normalized, 7);
        }

        $packed = @inet_pton($normalized);
        if ($packed === false) {
            return '';
        }

        $ip = inet_ntop($packed);

        return is_string($ip) ? strtolower($ip) : '';
    }

    protected function toPositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) return null;
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    protected function summarizeSelectedAttributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $name = trim((string) (
                $attribute['attribute_name']
                ?? $attribute['attributeName']
                ?? $attribute['attribute_slug']
                ?? $attribute['attributeSlug']
                ?? ''
            ));
            $label = trim((string) (
                $attribute['label']
                ?? $attribute['valueName']
                ?? $attribute['value_name']
                ?? $attribute['value']
                ?? ''
            ));

            if ($label === '') {
                continue;
            }

            $parts[] = $name !== '' ? "{$name}: {$label}" : $label;
        }

        return implode(', ', $parts);
    }
}
