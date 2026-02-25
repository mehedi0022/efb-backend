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
            'area' => 'nullable|integer|exists:shipping_charges,id',
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

            $snapshot[$rowId] = [
                'id' => $item['product_id'] ?? null,
                'name' => $item['product_name']
                    ?? data_get($item, 'product.name')
                    ?? 'Unknown Product',
                'qty' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'image' => $item['product_image']
                    ?? data_get($item, 'product.image.image')
                    ?? null,
                'options' => $options,
            ];
        }

        return $snapshot;
    }
}
