<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id');
            $table->char('cart_id', 36);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('external_product_id', 255)->nullable();
            $table->string('product_name', 255)->nullable();
            $table->string('product_image', 255)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2);
            $table->json('options')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('cart_id', 'cart_items_cart_id_foreign');
            $table->index('product_id', 'cart_items_product_id_foreign');
            $table->foreign('cart_id', 'cart_items_cart_id_foreign')->references('id')->on('carts')->cascadeOnDelete();
            $table->foreign('product_id', 'cart_items_product_id_foreign')->references('id')->on('products')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
