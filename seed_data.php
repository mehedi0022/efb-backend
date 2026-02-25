<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ShippingCharge;
use App\Models\Brand;
use Illuminate\Support\Str;

// Create Category
$category = Category::create([
    'name' => 'Beauty',
    'slug' => 'beauty',
    'image' => 'default.png',
    'status' => 1
]);

// Create Brand
$brand = Brand::create([
    'name' => 'Natural',
    'name_bn' => 'Natural',
    'slug' => 'natural',
    'status' => 1
]);

// Create Product
Product::create([
    'name' => 'Natural Green Shampoo Soap Bar',
    'slug' => 'natural-green-shampoo-soap-bar',
    'category_id' => $category->id,
    'brand_id' => $brand->id,
    'product_code' => 'P001',
    'purchase_price' => 50,
    'old_price' => 150,
    'new_price' => 120,
    'stock' => 100,
    'description' => 'A helper for hair growth.',
    'status' => 1,
]);

// Create Shipping Charges
ShippingCharge::create(['name' => 'Inside Dhaka', 'amount' => 80, 'status' => 'active']);
ShippingCharge::create(['name' => 'Outside Dhaka', 'amount' => 150, 'status' => 'active']);

echo "Seeding completed.\n";
