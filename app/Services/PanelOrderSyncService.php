<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PanelOrderSyncService
{
    public function sync(Order $order, array $validatedLines, array $checkoutData): Order
    {
        $baseUrl = rtrim((string) config('services.panel.base_url', ''), '/');
        $sellerCode = trim((string) config('services.panel.seller_code', ''));
        $userDomain = trim((string) config('services.panel.user_domain', ''));

        if ($baseUrl === '' || $sellerCode === '' || $userDomain === '') {
            throw ValidationException::withMessages([
                'panel_sync' => ['Panel sync config missing. Set PANEL_API_BASE_URL, EFB_SELLER_CODE and EFB_USER_DOMAIN.'],
            ]);
        }

        $items = [];
        foreach ($validatedLines as $line) {
            $item = $line['item'] ?? null;
            $options = is_array($item?->options ?? null) ? $item->options : [];

            $productId = $this->toPositiveInt($options['panel_product_id'] ?? $item?->product_id);
            $variantId = $this->toPositiveInt(
                $options['panel_variant_id']
                ?? $options['variant_id']
                ?? $options['size_variant_id']
                ?? $options['product_size_id']
            );
            $sellerProductId = $this->toPositiveInt($options['panel_seller_product_id'] ?? null);
            $quantity = max(1, (int) ($line['quantity'] ?? $item?->quantity ?? 1));
            $unitSalePrice = (float) ($line['sale_price'] ?? $item?->price ?? 0);

            if (!$productId || !$variantId) {
                throw ValidationException::withMessages([
                    'panel_sync' => ['Missing panel product/variant mapping in cart item.'],
                ]);
            }

            $row = [
                'productId' => $productId,
                'variantId' => $variantId,
                'quantity' => $quantity,
                'unitSalePrice' => round($unitSalePrice, 2),
            ];

            if ($sellerProductId) {
                $row['sellerProductId'] = $sellerProductId;
            }

            $items[] = $row;
        }

        $payload = [
            'sellerCode' => $sellerCode,
            'userDomain' => $userDomain,
            'sellerOrderRef' => 'EFB-' . $order->invoice_id,
            'idempotencyKey' => 'efb-order-' . $order->id,
            'customerName' => (string) ($checkoutData['name'] ?? ''),
            'customerPhone' => (string) ($checkoutData['phone'] ?? ''),
            'customerAddress' => (string) ($checkoutData['address'] ?? ''),
            'customerArea' => $this->normalizeNullableString($checkoutData['area'] ?? null),
            'customerDistrict' => $this->normalizeNullableString($checkoutData['district'] ?? null),
            'customerNote' => $this->normalizeNullableString($checkoutData['note'] ?? null),
            'deliveryCharge' => (float) ($order->shipping_charge ?? 0),
            'packagingCharge' => 0,
            'discountTotal' => (float) ($order->discount ?? 0),
            'codCharge' => 0,
            'items' => $items,
        ];

        $response = Http::timeout((int) config('services.panel.timeout', 20))
            ->connectTimeout((int) config('services.panel.connect_timeout', 5))
            ->acceptJson()
            ->asJson()
            ->post($baseUrl . '/order/seller/public', $payload);

        if (!$response->successful()) {
            $responseBody = $response->json();
            $serverMessage = is_array($responseBody)
                ? (string) ($responseBody['message'] ?? 'Panel order sync failed.')
                : 'Panel order sync failed.';
            $order->update([
                'panel_sync_status' => 'failed',
                'panel_sync_error' => mb_substr(
                    trim($serverMessage) !== '' ? $serverMessage : (string) $response->body(),
                    0,
                    3000
                ),
                'panel_synced_at' => null,
            ]);

            throw ValidationException::withMessages([
                'panel_sync' => ['Panel order sync failed.'],
            ]);
        }

        $body = $response->json();
        $panelOrder = $body['data'] ?? [];

        $order->update([
            'panel_order_id' => $this->toPositiveInt($panelOrder['id'] ?? null),
            'panel_order_no' => $this->normalizeNullableString($panelOrder['orderNo'] ?? null),
            'panel_sync_status' => 'synced',
            'panel_sync_error' => null,
            'panel_synced_at' => now(),
        ]);

        return $order->fresh();
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) return null;
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
