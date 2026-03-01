<?php

namespace App\Services;

use App\Models\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    /**
     * Canonical order statuses allowed in the system.
     *
     * @var array<string, array{id:int, name:string, slug:string, aliases:array<int,string>}>
     */
    private const DEFAULT_STATUSES = [
        'new_order' => [
            'id' => 1,
            'name' => 'New Order',
            'slug' => 'pending',
            'aliases' => ['new-order', 'new-orders', 'new', 'pending', '1'],
        ],
        'complete' => [
            'id' => 6,
            'name' => 'Complete',
            'slug' => 'complete',
            'aliases' => ['complete', 'completed', 'complete-order', 'complete-orders', '6'],
        ],
        'no_response' => [
            'id' => 9,
            'name' => 'No Response',
            'slug' => 'no-response',
            'aliases' => ['no-response', 'no-response-order', 'no-response-orders', '9', 'noresponse'],
        ],
        'hold' => [
            'id' => 8,
            'name' => 'Hold',
            'slug' => 'hold',
            'aliases' => ['hold', 'hold-order', 'hold-orders', '8', 'on-hold'],
        ],
        'cancel' => [
            'id' => 5,
            'name' => 'Cancel',
            'slug' => 'cancel',
            'aliases' => ['cancel', 'cancel-order', 'cancel-orders', 'cancelled', 'canceled', '5'],
        ],
        'fb_sent' => [
            'id' => 10,
            'name' => 'FB Sent',
            'slug' => 'fb-sent',
            'aliases' => ['fb-sent', 'fb_sent', 'fbsent', '10'],
        ],
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
                ->orWhere('id', (int) $definition['id'])
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

            $updates = [
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'status' => '1',
            ];

            if ((int) $status->id !== (int) $definition['id']) {
                $targetId = (int) $definition['id'];
                if (!OrderStatus::query()->where('id', $targetId)->exists()) {
                    $updates['id'] = $targetId;
                }
            }

            $status->fill($updates)->save();
        }

        $allowedIds = collect(self::DEFAULT_STATUSES)
            ->map(fn (array $status) => (int) $status['id'])
            ->values()
            ->all();

        OrderStatus::query()
            ->whereNotIn('id', $allowedIds)
            ->delete();

        return OrderStatus::query()
            ->whereIn('id', $allowedIds)
            ->orderBy('id')
            ->get();
    }

    public function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('_', '-', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }

    /**
     * @return array<int, string>
     */
    public function supportedCanonicalKeys(): array
    {
        return array_keys(self::DEFAULT_STATUSES);
    }

    public function canonicalLabel(string $canonicalKey): string
    {
        return self::DEFAULT_STATUSES[$canonicalKey]['name'] ?? 'Unknown';
    }

    public function canonicalKeyFromValue(mixed $value): ?string
    {
        $normalized = $this->normalizeSlug((string) $value);
        if ($normalized === '') {
            return null;
        }

        foreach (self::DEFAULT_STATUSES as $canonicalKey => $definition) {
            $aliases = $this->normalizeAliasesForDefinition($definition);
            if (in_array($normalized, $aliases, true)) {
                return $canonicalKey;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function aliasesForCanonicalKey(string $canonicalKey): array
    {
        $definition = self::DEFAULT_STATUSES[$canonicalKey] ?? null;
        if (!$definition) {
            return [$this->normalizeSlug($canonicalKey)];
        }

        return $this->normalizeAliasesForDefinition($definition);
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

        $definition = self::DEFAULT_STATUSES[$canonicalKey] ?? null;
        return $definition ? [(int) $definition['id']] : [];
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
            return self::DEFAULT_STATUSES[$canonical]['name'] ?? 'Unknown';
        }

        $clean = trim((string) $value);
        if ($clean === '') {
            return 'Unknown';
        }

        return ucfirst(str_replace('-', ' ', $this->normalizeSlug($clean)));
    }

    public function isCancelledValue(mixed $value): bool
    {
        return $this->canonicalKeyFromValue($value) === 'cancel';
    }

    /**
     * @param array{id:int,name:string,slug:string,aliases:array<int,string>} $definition
     * @return array<int, string>
     */
    private function normalizeAliasesForDefinition(array $definition): array
    {
        $aliases = [
            (string) $definition['id'],
            (string) $definition['slug'],
            (string) $definition['name'],
            ...($definition['aliases'] ?? []),
        ];

        return collect($aliases)
            ->map(fn ($value) => $this->normalizeSlug((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
