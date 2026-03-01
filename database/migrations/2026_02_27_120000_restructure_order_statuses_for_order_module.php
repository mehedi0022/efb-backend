<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_statuses')) {
            return;
        }

        $definitions = [
            ['id' => 1, 'name' => 'New Order', 'slug' => 'pending'],
            ['id' => 5, 'name' => 'Cancel', 'slug' => 'cancel'],
            ['id' => 6, 'name' => 'Complete', 'slug' => 'complete'],
            ['id' => 8, 'name' => 'Hold', 'slug' => 'hold'],
            ['id' => 9, 'name' => 'No Response', 'slug' => 'no-response'],
            ['id' => 10, 'name' => 'FB Sent', 'slug' => 'fb-sent'],
        ];

        foreach ($definitions as $definition) {
            DB::table('order_statuses')->updateOrInsert(
                ['id' => (int) $definition['id']],
                [
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'status' => '1',
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_status')) {
            $this->updateOrdersByAliases(
                ['1', 'pending', 'new-order', 'new order', 'new_order', 'processing', 'confirmed', '2', '3'],
                '1'
            );
            $this->updateOrdersByAliases(
                ['6', 'complete', 'completed', 'delivered', '4'],
                '6'
            );
            $this->updateOrdersByAliases(
                ['9', 'no-response', 'no response', 'no_response', 'noresponse'],
                '9'
            );
            $this->updateOrdersByAliases(
                ['8', 'hold', 'on hold', 'on-hold', 'ship later', 'ready to delivery', 'ready-to-delivery'],
                '8'
            );
            $this->updateOrdersByAliases(
                ['5', 'cancel', 'cancelled', 'canceled', 'return', 'returned', '7'],
                '5'
            );
            $this->updateOrdersByAliases(
                ['10', 'fb-sent', 'fb sent', 'fb_sent', 'fbsent'],
                '10'
            );

            DB::table('orders')
                ->whereNull('order_status')
                ->orWhereRaw("TRIM(COALESCE(order_status, '')) = ''")
                ->update(['order_status' => '1']);

            $allowed = collect([
                '1', 'pending', 'new-order',
                '5', 'cancel', 'cancelled', 'canceled',
                '6', 'complete', 'completed',
                '8', 'hold',
                '9', 'no-response',
                '10', 'fb-sent',
            ])->map(fn ($value) => $this->normalizeValue((string) $value))->unique()->values()->all();

            if (!empty($allowed)) {
                $placeholders = implode(',', array_fill(0, count($allowed), '?'));
                DB::table('orders')
                    ->whereRaw("LOWER(TRIM(REPLACE(REPLACE(COALESCE(order_status, ''), '_', '-'), ' ', '-'))) NOT IN ({$placeholders})", $allowed)
                    ->update(['order_status' => '1']);
            }
        }

        $allowedIds = collect($definitions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::table('order_statuses')->whereNotIn('id', $allowedIds)->delete();
    }

    public function down(): void
    {
        // Intentionally left as no-op because previous mixed status states are not deterministic.
    }

    /**
     * @param array<int, string> $aliases
     */
    private function updateOrdersByAliases(array $aliases, string $targetStatus): void
    {
        $normalized = collect($aliases)
            ->map(fn ($value) => $this->normalizeValue($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($normalized)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        DB::table('orders')
            ->whereRaw("LOWER(TRIM(REPLACE(REPLACE(COALESCE(order_status, ''), '_', '-'), ' ', '-'))) IN ({$placeholders})", $normalized)
            ->update(['order_status' => $targetStatus]);
    }

    private function normalizeValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('_', '-', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }
};
