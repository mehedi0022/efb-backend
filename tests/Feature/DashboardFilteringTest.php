<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_applies_start_and_end_date_filters(): void
    {
        Carbon::setTestNow('2026-03-31 23:00:00');

        try {
            $this->withoutMiddleware();

            $customerId = $this->seedCustomer();

            $this->seedOrder(
                customerId: $customerId,
                invoiceId: 'INV-MAR-001',
                amount: 500,
                status: 'pending',
                createdAt: '2026-03-05 10:15:00'
            );

            $this->seedOrder(
                customerId: $customerId,
                invoiceId: 'INV-MAR-002',
                amount: 700,
                status: 'complete',
                createdAt: '2026-03-31 10:30:00'
            );

            $this->seedOrder(
                customerId: $customerId,
                invoiceId: 'INV-FEB-001',
                amount: 900,
                status: 'pending',
                createdAt: '2026-02-25 09:00:00'
            );

            $response = $this->getJson('/api/admin/dashboard?start_date=2026-03-01&end_date=2026-03-31');

            $response
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('filters.start_date', '2026-03-01')
                ->assertJsonPath('filters.end_date', '2026-03-31')
                ->assertJsonPath('stats.total_order.count', 2)
                ->assertJsonPath('stats.total_order.amount', 1200)
                ->assertJsonPath('stats.new_order.count', 1)
                ->assertJsonPath('stats.completed_order.count', 1)
                ->assertJsonPath('monthly_orders.data.0.month', '2026-03')
                ->assertJsonPath('monthly_orders.data.0.order_count', 2);

            $latestInvoiceIds = collect($response->json('latest_orders'))
                ->pluck('invoice_id')
                ->all();

            $this->assertSame(['INV-MAR-002', 'INV-MAR-001'], $latestInvoiceIds);
            $this->assertSame(
                1,
                collect($response->json('hourly_orders.data'))->sum('order_count')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedCustomer(): int
    {
        $now = now();

        return DB::table('customers')->insertGetId([
            'ip_address' => '127.0.0.1',
            'name' => 'Dashboard Buyer',
            'slug' => 'dashboard-buyer-' . Str::lower(Str::random(6)),
            'phone' => '01710000000',
            'email' => 'dashboard-' . Str::lower(Str::random(6)) . '@example.com',
            'balance' => 0,
            'district' => 'Dhaka',
            'area' => 'Dhaka',
            'address' => 'Dhaka',
            'verify' => 1,
            'image' => 'public/uploads/default/user.png',
            'password' => bcrypt('password'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrder(
        int $customerId,
        string $invoiceId,
        int $amount,
        string $status,
        string $createdAt
    ): int {
        $timestamp = Carbon::parse($createdAt);

        return DB::table('orders')->insertGetId([
            'ip_address' => '127.0.0.1',
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'shipping_charge' => 0,
            'customer_id' => $customerId,
            'user_id' => null,
            'district' => null,
            'order_status' => $status,
            'note' => null,
            'admin_note' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
