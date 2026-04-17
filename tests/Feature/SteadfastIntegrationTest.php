<?php

namespace Tests\Feature;

use App\Models\Courierapi;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SteadfastIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    public function test_fetch_steadfast_config_returns_masked_configuration(): void
    {
        Courierapi::query()->create([
            'type' => 'steadfast',
            'status' => 1,
            'url' => 'https://steadfast.example.com/orders',
            'api_key' => 'api-key-123',
            'secret_key' => 'secret-key-456',
        ]);

        $response = $this->getJson('/api/admin/integrations/steadfast');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'steadfast')
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.url', 'https://steadfast.example.com/orders')
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.has_secret_key', true)
            ->assertJsonPath('data.api_key', '')
            ->assertJsonPath('data.secret_key', '');
    }

    public function test_update_steadfast_config_persists_expected_fields(): void
    {
        $response = $this->putJson('/api/admin/integrations/steadfast', [
            'status' => true,
            'url' => 'https://steadfast.example.com/create-order',
            'api_key' => 'new-api-key',
            'secret_key' => 'new-secret-key',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'steadfast')
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.has_secret_key', true);

        /** @var Courierapi $courier */
        $courier = Courierapi::query()->where('type', 'steadfast')->firstOrFail();

        $this->assertSame('https://steadfast.example.com/create-order', $courier->url);
        $this->assertSame('new-api-key', $courier->api_key);
        $this->assertSame('new-secret-key', $courier->secret_key);
        $this->assertSame(1, (int) $courier->status);
    }

    public function test_sending_completed_order_to_steadfast_updates_courier_fields(): void
    {
        Courierapi::query()->create([
            'type' => 'steadfast',
            'status' => 1,
            'url' => 'https://steadfast.example.com/api/v1',
            'api_key' => 'api-key-123',
            'secret_key' => 'secret-key-456',
        ]);

        $order = $this->createOrderWithDetails('complete');

        Http::fake([
            'https://steadfast.example.com/api/v1/create_order' => Http::response([
                'status' => 'success',
                'message' => 'Created successfully.',
                'consignment' => [
                    'consignment_id' => 'ST123456',
                    'status' => 'booked',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/orders/courier/steadfast', [
            'order_id' => $order->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sent_orders.0.courier_order_id', 'ST123456');

        $order->refresh();

        $this->assertSame('steadfast', $order->courier_name);
        $this->assertSame('booked', $order->courier_status);
        $this->assertSame('ST123456', $order->courier_order_id);
        $this->assertNull($order->courier_sync_error);
        $this->assertIsArray($order->courier_response_payload);
        $this->assertSame('ST123456', data_get($order->courier_response_payload, 'consignment.consignment_id'));

        Http::assertSentCount(1);
    }

    public function test_sending_non_completed_order_to_steadfast_is_rejected(): void
    {
        Courierapi::query()->create([
            'type' => 'steadfast',
            'status' => 1,
            'url' => 'https://steadfast.example.com/api/v1',
            'api_key' => 'api-key-123',
            'secret_key' => 'secret-key-456',
        ]);

        $order = $this->createOrderWithDetails('pending');

        Http::fake();

        $response = $this->postJson('/api/admin/orders/courier/steadfast', [
            'order_id' => $order->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertSee('Only completed orders can be sent to Steadfast.');

        Http::assertNothingSent();
    }

    public function test_sending_already_dispatched_order_to_steadfast_is_rejected(): void
    {
        Courierapi::query()->create([
            'type' => 'steadfast',
            'status' => 1,
            'url' => 'https://steadfast.example.com/api/v1',
            'api_key' => 'api-key-123',
            'secret_key' => 'secret-key-456',
        ]);

        $order = $this->createOrderWithDetails('complete', [
            'courier_name' => 'steadfast',
            'courier_status' => 'sent',
            'courier_order_id' => 'EXISTING123',
        ]);

        Http::fake();

        $response = $this->postJson('/api/admin/orders/courier/steadfast', [
            'order_id' => $order->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertSee('Order already taken by Steadfast.');

        Http::assertNothingSent();
    }

    public function test_failed_steadfast_dispatch_stores_error_state(): void
    {
        Courierapi::query()->create([
            'type' => 'steadfast',
            'status' => 1,
            'url' => 'https://steadfast.example.com/api/v1',
            'api_key' => 'api-key-123',
            'secret_key' => 'secret-key-456',
        ]);

        $order = $this->createOrderWithDetails('complete');

        Http::fake([
            'https://steadfast.example.com/api/v1/create_order' => Http::response([
                'status' => 'error',
                'message' => 'Invalid order payload.',
            ], 422),
        ]);

        $response = $this->postJson('/api/admin/orders/courier/steadfast', [
            'order_id' => $order->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertSee('Invalid order payload.');

        $order->refresh();

        $this->assertSame('steadfast', $order->courier_name);
        $this->assertSame('failed', $order->courier_status);
        $this->assertNull($order->courier_order_id);
        $this->assertSame('Invalid order payload.', $order->courier_sync_error);
        $this->assertIsArray($order->courier_response_payload);
        $this->assertSame('Invalid order payload.', data_get($order->courier_response_payload, 'message'));
    }

    private function createOrderWithDetails(string $orderStatus, array $orderOverrides = []): Order
    {
        $now = now();

        $customerId = DB::table('customers')->insertGetId([
            'ip_address' => '127.0.0.1',
            'name' => 'Test Customer',
            'slug' => 'test-customer-' . Str::lower(Str::random(6)),
            'phone' => '01710000000',
            'email' => 'customer@example.com',
            'address' => 'Dhaka',
            'password' => bcrypt('password'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderId = DB::table('orders')->insertGetId(array_merge([
            'invoice_id' => 'INV-' . Str::upper(Str::random(8)),
            'amount' => 500,
            'discount' => 0,
            'shipping_charge' => 60,
            'customer_id' => $customerId,
            'order_status' => $orderStatus,
            'ip_address' => '127.0.0.1',
            'created_at' => $now,
            'updated_at' => $now,
        ], $orderOverrides));

        DB::table('shippings')->insert([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'name' => 'Test Customer',
            'phone' => '01710000000',
            'address' => 'Dhaka Address',
            'area' => 'Dhaka',
            'ip_address' => '127.0.0.1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_details')->insert([
            'order_id' => $orderId,
            'product_id' => 1,
            'product_name' => 'Sample Product',
            'purchase_price' => 300,
            'sale_price' => 440,
            'qty' => 1,
            'image' => 'default.png',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Order::query()->findOrFail($orderId);
    }
}
