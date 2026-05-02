<?php

namespace App\Services;

use App\Models\Courierapi;
use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SteadfastCourierService
{
    public function __construct(protected ApiClient $apiClient)
    {
    }

    public function getConfiguration(): Courierapi
    {
        return Courierapi::query()->firstOrCreate(
            ['type' => 'steadfast'],
            [
                'status' => 0,
                'url' => null,
            ]
        );
    }

    public function formatConfiguration(Courierapi $courier): array
    {
        return [
            'id' => (int) $courier->id,
            'type' => (string) $courier->type,
            'url' => $courier->url,
            'status' => (int) ($courier->status ?? 0),
            'api_key' => '',
            'secret_key' => '',
            'has_api_key' => trim((string) ($courier->api_key ?? '')) !== '',
            'has_secret_key' => trim((string) ($courier->secret_key ?? '')) !== '',
            'updated_at' => optional($courier->updated_at)->toDateTimeString(),
        ];
    }

    public function updateConfiguration(array $validated): Courierapi
    {
        $courier = $this->getConfiguration();

        $payload = [
            'status' => array_key_exists('status', $validated)
                ? ((int) $validated['status'] === 1 ? 1 : 0)
                : (int) ($courier->status ?? 0),
            'url' => isset($validated['url']) && trim((string) $validated['url']) !== ''
                ? trim((string) $validated['url'])
                : null,
        ];

        if (array_key_exists('api_key', $validated)) {
            $payload['api_key'] = trim((string) ($validated['api_key'] ?? '')) ?: null;
        }

        if (array_key_exists('secret_key', $validated)) {
            $payload['secret_key'] = trim((string) ($validated['secret_key'] ?? '')) ?: null;
        }

        $courier->fill($payload);

        if (trim((string) ($courier->api_key ?? '')) !== '') {
            $courier->token = $courier->api_key;
        }

        $courier->save();

        return $courier->refresh();
    }

    /**
     * @return array{
     *   success: bool,
     *   courier_status: string,
     *   courier_order_id: string|null,
     *   message: string,
     *   response_payload: array<string, mixed>
     * }
     */
    public function dispatch(Order $order): array
    {
        $courier = $this->getConfiguration();
        $this->ensureReadyForDispatch($courier);

        $payload = $this->buildPayload($order);
        $response = $this->sendCreateOrderRequest($courier, $payload);
        $responsePayload = $this->normalizeResponsePayload($response);

        if ($response->successful()) {
            $courierOrderId = $this->extractCourierOrderId($responsePayload);
            $courierStatus = $this->extractCourierStatus($responsePayload) ?: 'sent';

            return [
                'success' => true,
                'courier_status' => $courierStatus,
                'courier_order_id' => $courierOrderId,
                'message' => $this->extractSuccessMessage($responsePayload),
                'response_payload' => $responsePayload,
            ];
        }

        return [
            'success' => false,
            'courier_status' => 'failed',
            'courier_order_id' => null,
            'message' => $this->resolveErrorMessage($response),
            'response_payload' => $responsePayload,
        ];
    }

    public function buildPayload(Order $order): array
    {
        $shipping = $order->shipping;
        $customer = $order->customer;

        $recipientName = trim((string) ($shipping?->name ?? $customer?->name ?? ''));
        $recipientPhone = trim((string) ($shipping?->phone ?? $customer?->phone ?? ''));
        $recipientAddress = trim((string) ($shipping?->address ?? $customer?->address ?? ''));
        $deliveryArea = trim((string) ($shipping?->area ?? ''));

        $items = $order->orderdetails->map(function ($detail) {
            return [
                'name' => (string) ($detail->product_name ?? 'Item'),
                'quantity' => max(1, (int) ($detail->qty ?? 1)),
                'price' => max(0, (float) ($detail->sale_price ?? 0)),
            ];
        })->values()->all();

        $itemSummary = collect($items)
            ->pluck('name')
            ->filter()
            ->take(3)
            ->implode(', ');

        return [
            'invoice' => (string) $order->invoice_id,
            'recipient_name' => $recipientName !== '' ? $recipientName : 'Customer',
            'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : '00000000000',
            'recipient_address' => $recipientAddress !== '' ? $recipientAddress : 'Address not provided',
            'delivery_area' => $deliveryArea,
            'cod_amount' => max(0, (float) ($order->amount ?? 0)),
            'note' => trim((string) ($order->note ?? '')),
            'item_description' => $itemSummary !== '' ? $itemSummary : 'Parcel',
            'total_quantity' => collect($items)->sum('quantity'),
            'items' => $items,
        ];
    }

    protected function ensureReadyForDispatch(Courierapi $courier): void
    {
        if ((int) ($courier->status ?? 0) !== 1) {
            throw ValidationException::withMessages([
                'steadfast' => ['Steadfast integration is inactive. Please activate it in Steadfast settings.'],
            ]);
        }

        if (trim((string) ($courier->url ?? '')) === '') {
            throw ValidationException::withMessages([
                'steadfast' => ['Steadfast base URL is missing. Please update Steadfast settings.'],
            ]);
        }

        if (trim((string) ($courier->api_key ?? '')) === '') {
            throw ValidationException::withMessages([
                'steadfast' => ['Steadfast API key is missing. Please update Steadfast settings.'],
            ]);
        }

        if (trim((string) ($courier->secret_key ?? '')) === '') {
            throw ValidationException::withMessages([
                'steadfast' => ['Steadfast secret key is missing. Please update Steadfast settings.'],
            ]);
        }
    }

    protected function sendCreateOrderRequest(Courierapi $courier, array $payload): Response
    {
        $apiKey = trim((string) ($courier->api_key ?? ''));
        $secretKey = trim((string) ($courier->secret_key ?? ''));
        $endpoint = $this->resolveCreateOrderEndpoint((string) ($courier->url ?? ''));

        return $this->apiClient
            ->request()
            ->retry(0, 0, throw: false)
            ->asJson()
            ->withHeaders([
                'Api-Key' => $apiKey,
                'Secret-Key' => $secretKey,
                'X-API-Key' => $apiKey,
                'X-Secret-Key' => $secretKey,
                'X-Steadfast-Api-Key' => $apiKey,
                'X-Steadfast-Secret-Key' => $secretKey,
            ])
            ->post($endpoint, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeResponsePayload(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        $body = trim((string) $response->body());

        return $body !== '' ? ['raw_body' => $body] : [];
    }

    protected function extractCourierOrderId(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'tracking_code'),
            data_get($payload, 'consignment.consignment_id'),
            data_get($payload, 'consignment_id'),
            data_get($payload, 'data.consignment.consignment_id'),
            data_get($payload, 'data.consignment_id'),
            data_get($payload, 'data.invoice'),
            data_get($payload, 'invoice'),
            data_get($payload, 'data.tracking_code'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function extractCourierStatus(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'consignment.status'),
            data_get($payload, 'data.consignment.status'),
            data_get($payload, 'status'),
            data_get($payload, 'data.status'),
            data_get($payload, 'delivery_status'),
            data_get($payload, 'data.delivery_status'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim(strtolower((string) ($candidate ?? '')));
            if ($value !== '') {
                return str_replace(' ', '_', $value);
            }
        }

        return null;
    }

    protected function extractSuccessMessage(array $payload): string
    {
        $candidates = [
            data_get($payload, 'message'),
            data_get($payload, 'data.message'),
        ];

        foreach ($candidates as $candidate) {
            $message = trim((string) ($candidate ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return 'Order sent to Steadfast successfully.';
    }

    protected function resolveErrorMessage(Response $response): string
    {
        $payload = $this->normalizeResponsePayload($response);
        $messageCandidates = Collection::make([
            data_get($payload, 'message'),
            data_get($payload, 'error'),
            data_get($payload, 'data.message'),
            data_get($payload, 'errors.0'),
            data_get($payload, 'raw_body'),
        ]);

        foreach ($messageCandidates as $candidate) {
            $message = trim((string) ($candidate ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return 'Steadfast request failed.';
    }

    protected function resolveCreateOrderEndpoint(string $configuredUrl): string
    {
        $candidate = rtrim(trim($configuredUrl), '/');

        if ($candidate === '') {
            return 'https://portal.packzy.com/api/v1/create_order';
        }

        if (preg_match('/\/create_order$/i', $candidate)) {
            return $candidate;
        }

        if (preg_match('/\/api\/v1$/i', $candidate)) {
            return $candidate . '/create_order';
        }

        return $candidate;
    }
}
