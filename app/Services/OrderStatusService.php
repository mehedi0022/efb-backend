<?php

namespace App\Services;

use App\Models\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    /**
     * @var array<int, array{id:int, name:string, slug:string}>
     */
    private const DEFAULT_STATUSES = [
        ['id' => 1, 'name' => 'Pending', 'slug' => 'pending'],
        ['id' => 2, 'name' => 'Processing', 'slug' => 'processing'],
        ['id' => 3, 'name' => 'Confirmed', 'slug' => 'confirmed'],
        ['id' => 4, 'name' => 'Delivered', 'slug' => 'delivered'],
        ['id' => 5, 'name' => 'Cancelled', 'slug' => 'cancelled'],
        ['id' => 6, 'name' => 'Complete', 'slug' => 'complete'],
        ['id' => 7, 'name' => 'Returned', 'slug' => 'returned'],
        ['id' => 8, 'name' => 'Hold', 'slug' => 'hold'],
    ];

    /**
     * @return \Illuminate\Support\Collection<int, OrderStatus>
     */
    public function ensureDefaultStatuses(): Collection
    {
        foreach (self::DEFAULT_STATUSES as $definition) {
            /** @var OrderStatus|null $status */
            $status = OrderStatus::query()
                ->whereRaw('LOWER(slug) = ?', [$definition['slug']])
                ->first();

            if (!$status) {
                $payload = [
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'status' => '1',
                ];

                if (!OrderStatus::query()->where('id', $definition['id'])->exists()) {
                    $payload['id'] = $definition['id'];
                }

                $status = new OrderStatus($payload);
                $status->save();
                continue;
            }

            $updates = [];
            if (trim((string) $status->name) === '') {
                $updates['name'] = $definition['name'];
            }
            if (trim((string) $status->slug) === '') {
                $updates['slug'] = $definition['slug'];
            }
            if ($status->status === null || trim((string) $status->status) === '') {
                $updates['status'] = '1';
            }

            if (!empty($updates)) {
                $status->fill($updates)->save();
            }
        }

        return OrderStatus::query()->orderBy('id')->get();
    }

    public function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('_', '-', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }

    public function canonicalKeyFromValue(mixed $value): ?string
    {
        $normalized = $this->normalizeSlug((string) $value);

        return match ($normalized) {
            '1', 'pending' => 'pending',
            '2', 'processing' => 'processing',
            '3', 'confirmed' => 'confirmed',
            '4', 'delivered' => 'delivered',
            '5', 'cancelled', 'canceled' => 'cancelled',
            '6', 'complete', 'completed' => 'complete',
            '7', 'return', 'returned' => 'returned',
            '8', 'hold', 'on-hold', 'ship-later', 'ready-to-delivery' => 'hold',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public function aliasesForCanonicalKey(string $canonicalKey): array
    {
        return match ($canonicalKey) {
            'pending' => ['pending', '1'],
            'processing' => ['processing', '2'],
            'confirmed' => ['confirmed', '3'],
            'delivered' => ['delivered', '4'],
            'cancelled' => ['cancelled', 'canceled', '5'],
            'complete' => ['complete', 'completed', '6'],
            'returned' => ['returned', 'return', '7'],
            'hold' => ['hold', 'on-hold', 'ship-later', 'ready-to-delivery', '8'],
            default => [$canonicalKey],
        };
    }

    /**
     * @return array<int, int>
     */
    public function statusIdsForCanonicalKey(string $canonicalKey): array
    {
        $aliases = $this->aliasesForCanonicalKey($canonicalKey);

        $ids = OrderStatus::query()
            ->where(function ($query) use ($aliases) {
                $query
                    ->whereIn(DB::raw('LOWER(slug)'), $aliases)
                    ->orWhereIn(DB::raw('LOWER(name)'), $aliases);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (!empty($ids)) {
            return $ids;
        }

        $fallback = collect(self::DEFAULT_STATUSES)
            ->first(fn (array $status) => $status['slug'] === $canonicalKey);

        return $fallback ? [(int) $fallback['id']] : [];
    }

    public function resolveStatusId(mixed $value, bool $ensureDefaults = true): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            $id = (int) $value;
            if (OrderStatus::query()->where('id', $id)->exists()) {
                return $id;
            }
        }

        $normalized = $this->normalizeSlug((string) $value);
        $canonical = $this->canonicalKeyFromValue($normalized);

        if ($canonical) {
            $ids = $this->statusIdsForCanonicalKey($canonical);
            if (!empty($ids)) {
                return (int) $ids[0];
            }
        }

        $status = OrderStatus::query()
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->orWhereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if ($status) {
            return (int) $status->id;
        }

        if ($ensureDefaults) {
            $this->ensureDefaultStatuses();
            return $this->resolveStatusId($value, false);
        }

        return null;
    }

    /**
     * @return array{ids:array<int, string>, raw_values:array<int, string>}
     */
    public function filterValuesForRoute(string $status): array
    {
        $normalized = $this->normalizeSlug($status);
        $canonical = $this->canonicalKeyFromValue($normalized);

        if ($canonical) {
            $aliases = $this->aliasesForCanonicalKey($canonical);
            $ids = $this->statusIdsForCanonicalKey($canonical);

            return [
                'ids' => collect($ids)->map(fn ($id) => (string) $id)->values()->all(),
                'raw_values' => $aliases,
            ];
        }

        $ids = OrderStatus::query()
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->orWhereRaw('LOWER(name) = ?', [$normalized])
            ->pluck('id')
            ->map(fn ($id) => (string) ((int) $id))
            ->values()
            ->all();

        return [
            'ids' => $ids,
            'raw_values' => [$normalized],
        ];
    }

    public function labelForValue(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return 'Unknown';
        }

        if (is_numeric($value)) {
            $status = OrderStatus::query()->find((int) $value);
            if ($status && trim((string) $status->name) !== '') {
                return (string) $status->name;
            }
        } else {
            $normalized = $this->normalizeSlug((string) $value);
            $status = OrderStatus::query()
                ->whereRaw('LOWER(slug) = ?', [$normalized])
                ->orWhereRaw('LOWER(name) = ?', [$normalized])
                ->first();

            if ($status && trim((string) $status->name) !== '') {
                return (string) $status->name;
            }
        }

        $canonical = $this->canonicalKeyFromValue($value);
        if ($canonical) {
            $definition = collect(self::DEFAULT_STATUSES)
                ->first(fn (array $status) => $status['slug'] === $canonical);

            if ($definition) {
                return $definition['name'];
            }
        }

        $clean = trim((string) $value);
        if ($clean === '') {
            return 'Unknown';
        }

        return ucfirst(str_replace('-', ' ', $this->normalizeSlug($clean)));
    }

    public function isCancelledValue(mixed $value): bool
    {
        return $this->canonicalKeyFromValue($value) === 'cancelled';
    }
}
