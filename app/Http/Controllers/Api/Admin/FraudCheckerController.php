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
    private const DEFAULT_ENDPOINT = 'https://fraudpeek.com/api/fraud-lookup';
    private const FORCED_CLIENT_ID = 'fp_00000R';
    private const FORCED_API_KEY = 'd3d15a33199c825a7d0bd56a18acae2f8ea6b5f061be803f2589759d410d584b';

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
        $missingCredentials = [];
        if ($config['client_id'] === '') {
            $missingCredentials[] = 'client ID';
        }
        if ($config['api_key'] === '') {
            $missingCredentials[] = 'API key';
        }

        if ($missingCredentials !== []) {
            return response()->json([
                'success' => false,
                'message' => 'FraudPeek credentials are not configured. Missing: ' . implode(', ', $missingCredentials) . '.',
            ], 422);
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->connectTimeout($config['connect_timeout'])
                ->retry($config['retry'], $config['retry_delay'], null, false)
                ->acceptJson()
                ->asForm()
                ->withHeaders([
                    'X-FP-Client-Id' => $config['client_id'],
                    'X-FP-API-Key' => $config['api_key'],
                ])
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

        $normalizedPayload = $this->normalizeProviderPayload($payload);
        $courierData = $normalizedPayload['courierData'];
        $summary = $normalizedPayload['summary'];

        if (!$normalizedPayload['has_data']) {
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
     * @return array{endpoint:string,client_id:string,api_key:string,timeout:int,connect_timeout:int,retry:int,retry_delay:int}
     */
    private function resolveConfig(): array
    {
        $legacyCandidateTypes = [
            'fraud-checker',
            'fraud_checker',
            'fraud-check',
            'bdcourier',
        ];

        $fraudPeekIntegration = Courierapi::query()
            ->where('type', 'fraudpeek')
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->first();

        $legacyIntegration = Courierapi::query()
            ->whereIn('type', $legacyCandidateTypes)
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->first();

        $endpoint = $this->firstNonEmptyString([
            config('services.fraudpeek.url'),
            $fraudPeekIntegration?->url,
            $legacyIntegration?->url,
            self::DEFAULT_ENDPOINT,
        ]);

        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        $clientId = self::FORCED_CLIENT_ID;
        $apiKey = self::FORCED_API_KEY;

        $timeout = max(3, (int) config('services.fraudpeek.timeout', config('services.api.timeout', 20)));
        $connectTimeout = max(1, (int) config('services.fraudpeek.connect_timeout', config('services.api.connect_timeout', 5)));
        $retry = max(0, min(5, (int) config('services.fraudpeek.retry', config('services.api.retry', 1))));
        $retryDelay = max(0, (int) config('services.fraudpeek.retry_delay', config('services.api.retry_delay', 500)));

        return [
            'endpoint' => $endpoint,
            'client_id' => $clientId,
            'api_key' => $apiKey,
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
    private function normalizeProviderPayload(array $payload): array
    {
        $courierData = $this->extractCourierData($payload);
        $summarySource = isset($courierData['summary']) && is_array($courierData['summary'])
            ? $courierData['summary']
            : $this->extractSummaryCandidate($payload);
        $summary = $this->normalizeSummary($summarySource);

        if (!array_key_exists('summary', $courierData)) {
            $courierData['summary'] = $summary;
        }

        $hasCourierBreakdown = collect($courierData)
            ->except('summary')
            ->isNotEmpty();

        return [
            'summary' => $summary,
            'courierData' => $courierData,
            'has_data' => $this->containsSummaryMetrics($summarySource) || $hasCourierBreakdown,
        ];
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
        $totalParcel = $this->toInt($this->pickFirstAvailable($summary, [
            'total_parcel',
            'total_parcels',
            'totalParcel',
            'total',
            'total_order',
            'total_orders',
            'total_consignment',
        ]));
        $successParcel = $this->toInt($this->pickFirstAvailable($summary, [
            'success_parcel',
            'delivered_parcels',
            'successParcel',
            'success',
            'delivered',
            'delivered_parcel',
        ]));
        $cancelledParcel = $this->toInt($this->pickFirstAvailable($summary, [
            'cancelled_parcel',
            'cancelled_parcels',
            'cancelledParcel',
            'cancelled',
            'returned',
            'return_parcel',
            'failed',
        ]));
        $pendingParcel = $this->toInt($this->pickFirstAvailable($summary, [
            'pending_parcel',
            'pendingParcel',
            'pending',
            'pending_order',
            'pending_orders',
        ]));

        if ($pendingParcel === 0 && !$this->containsAnyKey($summary, ['pending_parcel', 'pendingParcel', 'pending', 'pending_order', 'pending_orders'])) {
            $pendingParcel = $totalParcel - ($successParcel + $cancelledParcel);
        }

        if ($pendingParcel < 0) {
            $pendingParcel = 0;
        }

        $successRatioValue = $this->pickFirstAvailable($summary, [
            'success_ratio',
            'delivery_rate',
            'successRatio',
            'success_rate',
            'successRate',
        ]);
        $returnRatioValue = $this->pickFirstAvailable($summary, [
            'return_ratio',
            'return_percentage',
            'returnRatio',
            'return_rate',
            'returnRate',
            'cancel_ratio',
            'cancelRatio',
        ]);

        $successRatio = $successRatioValue !== null
            ? $this->normalizePercentage($successRatioValue)
            : ($totalParcel > 0 ? ($successParcel / $totalParcel) * 100 : 0);
        $returnRatio = $returnRatioValue !== null
            ? $this->normalizePercentage($returnRatioValue)
            : ($totalParcel > 0 ? ($cancelledParcel / $totalParcel) * 100 : 0);

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
        return (int) round($this->toFloat($value));
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) === 1) {
            return (float) $matches[0];
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCourierData(array $payload): array
    {
        $candidates = [
            data_get($payload, 'courierData'),
            data_get($payload, 'data.courierData'),
            data_get($payload, 'courier_data'),
            data_get($payload, 'data.courier_data'),
            data_get($payload, 'couriers'),
            data_get($payload, 'data.couriers'),
            data_get($payload, 'breakdown'),
            data_get($payload, 'data.breakdown'),
            data_get($payload, 'data'),
            $payload,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCourierCandidate($candidate);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCourierCandidate(mixed $candidate): array
    {
        if (!is_array($candidate) || $candidate === []) {
            return [];
        }

        if ($this->isListArray($candidate)) {
            $normalizedList = [];

            foreach ($candidate as $index => $item) {
                if (!is_array($item) || !$this->arrayHasCourierStats($item)) {
                    continue;
                }

                $key = $this->resolveCourierKey((string) $index, $item);
                $normalizedList[$key] = $this->normalizeCourierItem($key, $item);
            }

            return $normalizedList;
        }

        $normalized = [];

        if (isset($candidate['summary']) && is_array($candidate['summary'])) {
            $normalized['summary'] = $this->normalizeSummary($candidate['summary']);
        } elseif ($this->containsSummaryMetrics($candidate)) {
            $normalized['summary'] = $this->normalizeSummary($candidate);
        }

        foreach (['couriers', 'breakdown', 'providers', 'sources'] as $nestedKey) {
            if (!isset($candidate[$nestedKey]) || !is_array($candidate[$nestedKey])) {
                continue;
            }

            $normalized = array_merge($normalized, $this->normalizeCourierCandidate($candidate[$nestedKey]));
        }

        foreach ($candidate as $key => $value) {
            if (!is_array($value) || !$this->arrayHasCourierStats($value)) {
                continue;
            }

            if (in_array($key, ['summary', 'couriers', 'courierData', 'courier_data', 'breakdown', 'providers', 'sources', 'data'], true)) {
                continue;
            }

            $resolvedKey = $this->resolveCourierKey((string) $key, $value);
            $normalized[$resolvedKey] = $this->normalizeCourierItem($resolvedKey, $value);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCourierItem(string $key, array $value): array
    {
        $summary = $this->normalizeSummary(isset($value['summary']) && is_array($value['summary']) ? $value['summary'] : $value);

        return array_merge($value, [
            'name' => trim((string) ($value['name'] ?? $value['courier_name'] ?? $value['courier'] ?? Str::headline(str_replace(['_', '-'], ' ', $key)))),
            'total_parcel' => $summary['total_parcel'],
            'success_parcel' => $summary['success_parcel'],
            'cancelled_parcel' => $summary['cancelled_parcel'],
            'pending_parcel' => $summary['pending_parcel'],
            'success_ratio' => $summary['success_ratio'],
            'return_ratio' => $summary['return_ratio'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSummaryCandidate(array $payload): array
    {
        $candidates = [
            data_get($payload, 'summary'),
            data_get($payload, 'data.summary'),
            data_get($payload, 'courierData.summary'),
            data_get($payload, 'data.courierData.summary'),
            data_get($payload, 'stats'),
            data_get($payload, 'data.stats'),
            data_get($payload, 'result.summary'),
            data_get($payload, 'data.result.summary'),
            data_get($payload, 'data'),
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $this->containsSummaryMetrics($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function arrayHasCourierStats(array $value): bool
    {
        return $this->containsSummaryMetrics($value)
            || (isset($value['summary']) && is_array($value['summary']) && $this->containsSummaryMetrics($value['summary']));
    }

    private function containsSummaryMetrics(array $value): bool
    {
        if ($this->containsAnyKey($value, [
            'total_parcel',
            'total_parcels',
            'totalParcel',
            'total_order',
            'total_orders',
            'total_consignment',
            'success_parcel',
            'delivered_parcels',
            'successParcel',
            'delivered',
            'delivered_parcel',
            'cancelled_parcel',
            'cancelled_parcels',
            'cancelledParcel',
            'returned',
            'return_parcel',
            'pending_parcel',
            'pendingParcel',
            'pending_order',
            'pending_orders',
            'success_ratio',
            'delivery_rate',
            'successRatio',
            'success_rate',
            'successRate',
            'return_ratio',
            'return_percentage',
            'returnRatio',
            'return_rate',
            'returnRate',
            'cancel_ratio',
            'cancelRatio',
        ])) {
            return true;
        }

        return $this->countNumericMetricMatches($value, [
            'total',
            'success',
            'cancelled',
            'pending',
            'failed',
        ]) >= 2;
    }

    private function containsAnyKey(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function pickFirstAvailable(array $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $candidate = $value[$key];
            if ($candidate === null) {
                continue;
            }

            if (is_string($candidate) && trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function countNumericMetricMatches(array $value, array $keys): int
    {
        $matches = 0;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $value) || !$this->isNumericLike($value[$key])) {
                continue;
            }

            $matches++;
        }

        return $matches;
    }

    private function normalizePercentage(mixed $value): float
    {
        $number = $this->toFloat($value);

        if ($number > 0 && $number <= 1) {
            return $number * 100;
        }

        return $number;
    }

    private function resolveCourierKey(string $fallbackKey, array $value): string
    {
        $label = trim((string) ($value['slug'] ?? $value['name'] ?? $value['courier_name'] ?? $value['courier'] ?? ''));
        if ($label !== '') {
            return (string) Str::of($label)->slug('_');
        }

        if ($fallbackKey !== '' && !is_numeric($fallbackKey)) {
            return $fallbackKey;
        }

        return 'courier_' . $fallbackKey;
    }

    private function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function isNumericLike(mixed $value): bool
    {
        if (is_numeric($value)) {
            return true;
        }

        return is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value) === 1;
    }

    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

}
