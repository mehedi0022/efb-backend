<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Productsize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SizePricingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_add_rejects_mismatched_size_price(): void
    {
        [$product, $variant] = $this->seedProductWithVariant(basePrice: 220, variantPrice: 140);

        $cartResponse = $this->getJson('/api/v1/cart');
        $cartResponse->assertOk();
        $cartId = $cartResponse->json('cart_id');

        $response = $this
            ->withHeader('X-Cart-ID', $cartId)
            ->postJson('/api/v1/cart/add', [
                'product_id' => $product->id,
                'quantity' => 1,
                'options' => [
                    'product_size_id' => $variant->id,
                    'product_size' => $variant->size_name,
                    'price' => 99,
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertSee('Selected size price is invalid.');
    }

    public function test_cart_add_accepts_valid_variant_price_and_normalizes_options(): void
    {
        [$product, $variant] = $this->seedProductWithVariant(basePrice: 220, variantPrice: 140);

        $cartResponse = $this->getJson('/api/v1/cart');
        $cartResponse->assertOk();
        $cartId = $cartResponse->json('cart_id');

        $response = $this
            ->withHeader('X-Cart-ID', $cartId)
            ->postJson('/api/v1/cart/add', [
                'product_id' => $product->id,
                'quantity' => 1,
                'options' => [
                    'product_size_id' => $variant->id,
                    'price' => 140,
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.options.product_size_id', $variant->id)
            ->assertJsonPath('data.items.0.options.product_size', $variant->size_name)
            ->assertJsonPath('data.items.0.price', '140.00');
    }

    public function test_checkout_rejects_cart_line_when_variant_price_is_tampered(): void
    {
        [$product, $variant] = $this->seedProductWithVariant(basePrice: 200, variantPrice: 150);

        $cart = Cart::create(['status' => 'active']);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 99,
            'options' => [
                'product_size_id' => $variant->id,
                'product_size' => $variant->size_name,
                'price' => 99,
            ],
        ]);

        $response = $this
            ->withHeader('X-Cart-ID', $cart->id)
            ->postJson('/api/v1/checkout', [
                'name' => 'Test Buyer',
                'phone' => '01710000000',
                'email' => 'buyer@example.com',
                'address' => 'Dhaka',
                'district' => 'Dhaka',
                'payment_method' => 'cod',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.items.0', "Price mismatch for {$product->name} and selected size.");
    }

    public function test_cart_add_without_size_uses_default_variant_price(): void
    {
        $product = $this->seedProductWithMultipleVariants([
            ['size_name' => 'M', 'price' => 120, 'is_default' => 0],
            ['size_name' => 'L', 'price' => 160, 'is_default' => 1],
        ]);

        $cartResponse = $this->getJson('/api/v1/cart');
        $cartResponse->assertOk();
        $cartId = $cartResponse->json('cart_id');

        $response = $this
            ->withHeader('X-Cart-ID', $cartId)
            ->postJson('/api/v1/cart/add', [
                'product_id' => $product->id,
                'quantity' => 1,
                'options' => [],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.price', '160.00')
            ->assertJsonPath('data.items.0.options.product_size', 'L');
    }

    /**
     * @return array{0: Product, 1: Productsize}
     */
    private function seedProductWithVariant(int $basePrice, int $variantPrice): array
    {
        $now = now();

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Category',
            'slug' => 'category-' . Str::random(6),
            'parent_id' => 0,
            'image' => 'default.png',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'name' => 'Variant Product ' . Str::random(4),
            'slug' => 'variant-product-' . Str::random(6),
            'category_id' => $categoryId,
            'brand_id' => null,
            'product_code' => 'SKU-' . Str::upper(Str::random(8)),
            'purchase_price' => 100,
            'old_price' => null,
            'new_price' => $basePrice,
            'stock' => 10,
            'description' => 'Test product',
            'status' => 1,
            'topsale' => 0,
            'feature_product' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sizeId = DB::table('sizes')->insertGetId([
            'sizeName' => 'XL',
            'status' => '1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productSizeId = DB::table('productsizes')->insertGetId([
            'product_id' => $productId,
            'size_id' => $sizeId,
            'size_name' => 'XL',
            'price' => $variantPrice,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $product = Product::query()->findOrFail($productId);
        $variant = Productsize::query()->findOrFail($productSizeId);

        return [$product, $variant];
    }

    private function seedProductWithMultipleVariants(array $variants): Product
    {
        $now = now();

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Category',
            'slug' => 'category-' . Str::random(6),
            'parent_id' => 0,
            'image' => 'default.png',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'name' => 'Variant Product ' . Str::random(4),
            'slug' => 'variant-product-' . Str::random(6),
            'category_id' => $categoryId,
            'brand_id' => null,
            'product_code' => 'SKU-' . Str::upper(Str::random(8)),
            'purchase_price' => 100,
            'old_price' => null,
            'new_price' => 100,
            'stock' => 10,
            'description' => 'Test product',
            'status' => 1,
            'topsale' => 0,
            'feature_product' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($variants as $index => $variant) {
            $sizeId = DB::table('sizes')->insertGetId([
                'sizeName' => $variant['size_name'],
                'status' => '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('productsizes')->insert([
                'product_id' => $productId,
                'size_id' => $sizeId,
                'size_name' => $variant['size_name'],
                'price' => $variant['price'],
                'sort_order' => $index,
                'is_default' => (int) ($variant['is_default'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return Product::query()->findOrFail($productId);
    }
}
