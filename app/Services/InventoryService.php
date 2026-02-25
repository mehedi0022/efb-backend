<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryService
{
    public function attachAvailability($items, ?array $stockMap = null)
    {
        $paginator = ($items instanceof LengthAwarePaginator || $items instanceof Paginator) ? $items : null;
        $collection = $paginator ? $items->getCollection() : collect($items);

        if ($collection->isEmpty()) {
            return $items;
        }

        $skus = $collection->pluck('sku')->filter()->unique()->values()->all();
        $map  = $stockMap ?? $this->fetchAvailabilityMap($skus);

        $collection->transform(function ($product) use ($map) {
            $sku = data_get($product, 'sku');

            // Prefer batch availability; optionally fall back to single fetch
            $availability = $map[$sku] ?? ($this->allowSingleFallback() ? $this->fetchSingleInventory($sku) : null);
            if (!$availability) {
                $availability = $this->defaultFullInventory((int) data_get($product, 'stock', 0));
            }

            $product->inventory = $availability;
            $product->stock = (int) data_get($availability, 'available', (int) data_get($product, 'stock', 0));

            return $product;
        });

        if ($paginator) {
            $paginator->setCollection($collection);
            return $paginator;
        }

        return $collection;
    }

    public function attachFullInventory($product)
    {
        if (!$product) {
            return $product;
        }

        $sku = data_get($product, 'sku');

        $inventory = $this->allowSingleFallback() ? $this->fetchSingleInventory($sku) : null;
        if (!$inventory) {
            $inventory = $this->defaultFullInventory((int) data_get($product, 'stock', 0));
        }

        $product->inventory = $inventory;
        $product->stock = (int) data_get($inventory, 'available', (int) data_get($product, 'stock', 0));

        return $product;
    }

    public function fetchAvailabilityMap(array $skus): array
    {
        if (empty($skus) || !$this->isEnabled()) {
            return [];
        }

        try {
            $skus = array_values(array_unique(array_filter($skus)));
            if (empty($skus)) {
                return [];
            }

            $cacheKey = 'inventory:batch:' . md5(json_encode($skus));
            $ttl = $this->cacheTtlSeconds();

            return Cache::remember($cacheKey, $ttl, function () use ($skus) {
                $response = $this->httpClient()->post($this->batchAvailabilityUrl(), ['skus' => $skus]);

                if (!$response->successful()) {
                    Log::error('Inventory batch request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return [];
                }

                $payload = $response->json();

                // যদি success key থাকে এবং false হয়, তখন fail ধরবো
                if (Arr::has($payload, 'success') && !Arr::get($payload, 'success')) {
                    Log::error('Inventory batch request unsuccessful', ['response' => $payload]);
                    return [];
                }

                // Support wrapped (`items`), alternative (`data`), or direct list responses
                $items = Arr::get($payload, 'items') ?? Arr::get($payload, 'data');
                if ($items === null && Arr::isList($payload)) {
                    $items = $payload;
                } elseif ($items === null) {
                    $items = [];
                }

                $map = [];
                foreach ($items as $item) {
                    if (!isset($item['sku'])) {
                        continue;
                    }

                    $map[$item['sku']] = [
                        'available' => (int) ($item['available'] ?? $item['stock'] ?? 0),
                        'updatedAt' => $item['updatedAt'] ?? null,
                    ];
                }

                return $map;
            });
        } catch (Throwable $e) {
            Log::error('Inventory batch request exception', [
                'error' => $e->getMessage(),
                'skus'  => $skus,
            ]);
            return [];
        }
    }

    private function fetchSingleInventory(?string $sku): array
    {
        if (!$sku || !$this->isEnabled()) {
            return $this->defaultFullInventory();
        }

        try {
            $cacheKey = 'inventory:single:' . $sku;
            $ttl = $this->cacheTtlSeconds();

            return Cache::remember($cacheKey, $ttl, function () use ($sku) {
                $response = $this->httpClient()->get($this->singleProductUrl() . $sku);
                if (!$response->successful()) {
                    Log::error('Inventory single request failed', [
                        'sku' => $sku,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return $this->defaultFullInventory();
                }

                $payload = $response->json();

                // যদি success key থাকে এবং false হয়, তখন fail ধরবো
                if (Arr::has($payload, 'success') && !Arr::get($payload, 'success')) {
                    Log::error('Inventory single request unsuccessful', [
                        'sku' => $sku,
                        'response' => $payload,
                    ]);
                    return $this->defaultFullInventory();
                }

                // Support both wrapped (`data`) and direct payload shapes
                $data = Arr::get($payload, 'data', $payload);

                // কখনো কখনো data list/array হতে পারে: data[0]
                if (is_array($data) && Arr::isList($data)) {
                    $data = $data[0] ?? [];
                }

                return [
                    'onHand'    => (int) ($data['onHand'] ?? 0),
                    'reserved'  => (int) ($data['reserved'] ?? 0),
                    'available' => (int) ($data['available'] ?? $data['stock'] ?? 0),
                    'updatedAt' => $data['updatedAt'] ?? null,
                ];
            });
        } catch (Throwable $e) {
            Log::error('Inventory single request exception', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return $this->defaultFullInventory();
        }
    }

    private function defaultFullInventory(int $fallbackAvailable = 0): array
    {
        return [
            'onHand' => 0,
            'reserved' => 0,
            'available' => $fallbackAvailable,
            'updatedAt' => null,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) config('inventory.enabled', true);
    }

    private function allowSingleFallback(): bool
    {
        return (bool) config('inventory.fallback_single', false);
    }

    private function cacheTtlSeconds(): int
    {
        return (int) config('inventory.cache_ttl', 60);
    }

    private function httpClient()
    {
        $timeout = (int) config('inventory.timeout', 3);
        $connectTimeout = (int) config('inventory.connect_timeout', 2);

        $request = Http::timeout($timeout)->connectTimeout($connectTimeout);

        if (config('inventory.force_ipv4')) {
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $request = $request->withOptions([
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ]);
            }
        }

        return $request;
    }

    private function inventoryBaseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.api.base_url'), '/');

        return $baseUrl !== '' ? $baseUrl : 'http://api.freelancerbangladesh.com';
    }

    private function singleProductUrl(): string
    {
        return $this->inventoryBaseUrl() . '/inventory/availability/';
    }

    private function batchAvailabilityUrl(): string
    {
        return $this->inventoryBaseUrl() . '/inventory/availability/batch';
    }
}
