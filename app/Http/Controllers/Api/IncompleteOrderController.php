<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncompleteOrder;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IncompleteOrderController extends Controller
{
    public function __construct(protected CartService $cartService)
    {
    }

    public function track(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:20|min:3',
            'name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'area' => 'nullable|integer',
            'district' => 'nullable|string|max:255',
            'cart_id' => 'nullable|string|max:64',
            'status' => 'nullable|string|max:255',
        ]);

        $cartId = trim((string) ($request->header('X-Cart-ID') ?: ($validated['cart_id'] ?? '')));
        if ($cartId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Cart ID required.',
            ], 400);
        }

        $cart = $this->cartService->getCart($cartId, null);
        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => true,
                'saved' => false,
                'message' => 'Skipped. Cart is empty.',
            ]);
        }

        $cartData = $this->buildCartSnapshot($cart->items->toArray());
        $status = trim((string) ($validated['status'] ?? 'Incomplete')) ?: 'Incomplete';

        $incompleteOrder = IncompleteOrder::query()
            ->where('cart_id', $cartId)
            ->whereRaw("LOWER(COALESCE(status, '')) != 'completed'")
            ->orderByDesc('id')
            ->first();

        $name = trim((string) ($validated['name'] ?? ''));
        $address = trim((string) ($validated['address'] ?? ''));

        $districtId = is_numeric($validated['district'] ?? null)
            ? (int) $validated['district']
            : $incompleteOrder?->district_id;
        $shippingChargeId = isset($validated['area'])
            ? (int) $validated['area']
            : $incompleteOrder?->shipping_charge_id;

        $payload = [
            'name' => $name !== '' ? $name : ($incompleteOrder?->name ?: null),
            'phone' => trim((string) $validated['phone']),
            'cart_id' => $cartId,
            'address' => $address !== '' ? $address : ($incompleteOrder?->address ?: null),
            'district_id' => ($districtId && $districtId > 0) ? $districtId : null,
            'shipping_charge_id' => ($shippingChargeId && $shippingChargeId > 0) ? $shippingChargeId : null,
            'discount' => (float) ($incompleteOrder?->discount ?? 0),
            'cart_data' => json_encode($cartData, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'ip_address' => $request->ip(),
        ];

        if ($incompleteOrder) {
            $incompleteOrder->update($payload);
        } else {
            $incompleteOrder = IncompleteOrder::create($payload);
        }

        return response()->json([
            'success' => true,
            'saved' => true,
            'message' => 'Incomplete order tracked successfully.',
            'data' => [
                'id' => $incompleteOrder->id,
                'phone' => $incompleteOrder->phone,
                'status' => $incompleteOrder->status,
            ],
        ]);
    }

    private function buildCartSnapshot(array $items): array
    {
        $snapshot = [];

        foreach ($items as $item) {
            $rowId = (string) ($item['id'] ?? Str::uuid()->toString());
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $productId = isset($item['product_id']) && is_numeric($item['product_id'])
                ? (int) $item['product_id']
                : null;
            $externalProductId = trim((string) ($item['external_product_id'] ?? ''));
            $resolvedSku = trim((string) (
                $options['sku']
                ?? $options['product_sku']
                ?? data_get($item, 'product.sku')
                ?? data_get($item, 'product.product_code')
                ?? $externalProductId
                ?? ''
            ));

            if ($resolvedSku !== '') {
                $options['sku'] = $resolvedSku;
                $options['product_sku'] = $resolvedSku;
            }

            $snapshot[$rowId] = [
                'id' => $productId,
                'product_id' => $productId,
                'external_product_id' => $externalProductId !== '' ? $externalProductId : null,
                'name' => $item['product_name']
                    ?? data_get($item, 'product.name')
                    ?? 'Unknown Product',
                'qty' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'image' => $item['product_image']
                    ?? data_get($item, 'product.image.image')
                    ?? null,
                'sku' => $resolvedSku !== '' ? $resolvedSku : null,
                'product_sku' => $resolvedSku !== '' ? $resolvedSku : null,
                'product_size_id' => $options['product_size_id'] ?? $options['size_id'] ?? null,
                'product_color_id' => $options['product_color_id'] ?? $options['color_id'] ?? null,
                'product_size' => $options['product_size'] ?? $options['size'] ?? null,
                'product_color' => $options['product_color'] ?? $options['color'] ?? null,
                'options' => $options,
            ];
        }

        return $snapshot;
    }
}
