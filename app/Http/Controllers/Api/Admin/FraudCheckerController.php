<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courierapi;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class FraudCheckerController extends Controller
{
    private const DEFAULT_ENDPOINT = 'https://bdcourier.com/api/courier-check';
    private const EXAMPLE_PROJECT_FALLBACK_TOKEN = 'ScCjabwOxvcmvx3yAVD8kWAirSNRb5twJJFoNfAIHb0rJ1Cg2jWRLZSsvHeT';

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'save_to_order' => ['nullable', 'boolean'],
        ]);

        $phone = $this->normalizePhone((string) $validated['phone']);
        if ($phone === '') {
            return response()->json([
                'success' => false,
                'message' => 'A valid phone number is required.',
            ], 422);
        }

        $config = $this->resolveConfig();
        if ($config['token'] === '') {
            return response()->json([
                'success' => false,
                'message' => 'Fraud checker token is not configured. Please set it in courier integrations or .env.',
            ], 422);
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->connectTimeout($config['connect_timeout'])
                ->retry($config['retry'], $config['retry_delay'], null, false)
                ->acceptJson()
                ->withToken($config['token'])
                ->asJson()
                ->post($config['endpoint'], [
                    'phone' => $phone,
                ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to contact fraud checker provider.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Fraud checker provider returned an error.',
                'upstream_status' => $response->status(),
                'upstream_message' => Str::limit(trim($response->body()), 500),
            ], 502);
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid fraud checker response format.',
            ], 502);
        }

        $courierData = $this->extractCourierData($payload);
        if (!is_array($courierData) || empty($courierData)) {
            return response()->json([
                'success' => true,
                'message' => 'No fraud checker data found for this phone number.',
                'data' => [
                    'phone' => $phone,
                    'summary' => $this->normalizeSummary([]),
                    'courierData' => [],
                    'checked_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        $summaryRaw = isset($courierData['summary']) && is_array($courierData['summary'])
            ? $courierData['summary']
            : [];
        $summary = $this->normalizeSummary($summaryRaw);

        if (!array_key_exists('summary', $courierData)) {
            $courierData['summary'] = $summary;
        }

        if (
            !empty($validated['order_id']) &&
            (bool) ($validated['save_to_order'] ?? false)
        ) {
            $this->saveSummaryOnOrder((int) $validated['order_id'], $summary);
        }

        return response()->json([
            'success' => true,
            'message' => 'Fraud checker data fetched successfully.',
            'data' => [
                'phone' => $phone,
                'summary' => $summary,
                'courierData' => $courierData,
                'checked_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @return array{endpoint:string,token:string,timeout:int,connect_timeout:int,retry:int,retry_delay:int}
     */
    private function resolveConfig(): array
    {
        $candidateTypes = [
            'fraud-checker',
            'fraud_checker',
            'fraud-check',
            'bdcourier',
        ];

        $integration = Courierapi::query()
            ->whereIn('type', $candidateTypes)
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->first();

        $endpoint = trim((string) ($integration?->url ?? ''));
        if ($endpoint === '') {
            $endpoint = trim((string) config('services.bdcourier.url', self::DEFAULT_ENDPOINT));
        }
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        $token = trim((string) ($integration?->token ?? ''));
        if ($token === '') {
            $token = trim((string) ($integration?->api_key ?? ''));
        }
        if ($token === '') {
            $token = trim((string) config('services.bdcourier.token', ''));
        }
        if ($token === '') {
            $token = self::EXAMPLE_PROJECT_FALLBACK_TOKEN;
        }
        if (Str::startsWith(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        $timeout = max(3, (int) config('services.bdcourier.timeout', config('services.api.timeout', 20)));
        $connectTimeout = max(1, (int) config('services.bdcourier.connect_timeout', config('services.api.connect_timeout', 5)));
        $retry = max(0, min(5, (int) config('services.bdcourier.retry', config('services.api.retry', 1))));
        $retryDelay = max(0, (int) config('services.bdcourier.retry_delay', config('services.api.retry_delay', 500)));

        return [
            'endpoint' => $endpoint,
            'token' => $token,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'retry' => $retry,
            'retry_delay' => $retryDelay,
        ];
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '8801') && strlen($digits) === 13) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '1') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCourierData(array $payload): array
    {
        $candidates = [
            data_get($payload, 'courierData'),
            data_get($payload, 'data.courierData'),
            data_get($payload, 'data'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{
     *   total_parcel:int,
     *   success_parcel:int,
     *   cancelled_parcel:int,
     *   pending_parcel:int,
     *   success_ratio:float,
     *   return_ratio:float
     * }
     */
    private function normalizeSummary(array $summary): array
    {
        $totalParcel = $this->toInt($summary['total_parcel'] ?? 0);
        $successParcel = $this->toInt($summary['success_parcel'] ?? 0);
        $cancelledParcel = $this->toInt($summary['cancelled_parcel'] ?? 0);
        $pendingParcel = $this->toInt(
            $summary['pending_parcel'] ?? $summary['pending'] ?? ($totalParcel - ($successParcel + $cancelledParcel))
        );

        if ($pendingParcel < 0) {
            $pendingParcel = 0;
        }

        $successRatio = $this->toFloat(
            $summary['success_ratio'] ?? ($totalParcel > 0 ? ($successParcel / $totalParcel) * 100 : 0)
        );
        $returnRatio = $this->toFloat(
            $summary['return_ratio'] ?? ($totalParcel > 0 ? ($cancelledParcel / $totalParcel) * 100 : 0)
        );

        return [
            'total_parcel' => $totalParcel,
            'success_parcel' => $successParcel,
            'cancelled_parcel' => $cancelledParcel,
            'pending_parcel' => $pendingParcel,
            'success_ratio' => round($successRatio, 2),
            'return_ratio' => round($returnRatio, 2),
        ];
    }

    /**
     * @param array{
     *   total_parcel:int,
     *   success_parcel:int,
     *   cancelled_parcel:int,
     *   pending_parcel:int,
     *   success_ratio:float,
     *   return_ratio:float
     * } $summary
     */
    private function saveSummaryOnOrder(int $orderId, array $summary): void
    {
        $updates = [];

        if (Schema::hasColumn('orders', 'success_ratio')) {
            $updates['success_ratio'] = $summary['success_ratio'];
        }
        if (Schema::hasColumn('orders', 'total_parcel')) {
            $updates['total_parcel'] = $summary['total_parcel'];
        }
        if (Schema::hasColumn('orders', 'delivered')) {
            $updates['delivered'] = $summary['success_parcel'];
        }
        if (Schema::hasColumn('orders', 'cancelled')) {
            $updates['cancelled'] = $summary['cancelled_parcel'];
        }
        if (Schema::hasColumn('orders', 'pending')) {
            $updates['pending'] = $summary['pending_parcel'];
        }
        if (Schema::hasColumn('orders', 'return_ratio')) {
            $updates['return_ratio'] = $summary['return_ratio'];
        }

        if (!empty($updates)) {
            Order::query()->whereKey($orderId)->update($updates);
        }
    }

    private function toInt(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
