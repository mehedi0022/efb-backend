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
        Schema::create('products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->integer('category_id');
            $table->integer('brand_id')->nullable();
            $table->string('product_code', 255);
            $table->integer('purchase_price');
            $table->integer('old_price')->nullable();
            $table->integer('new_price');
            $table->integer('stock');
            $table->text('meta_description')->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('topsale')->nullable();
            $table->tinyInteger('feature_product')->nullable();
            $table->tinyInteger('campaign_id')->nullable();
            $table->tinyInteger('status');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('product_code', 'products_product_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
