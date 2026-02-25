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
        Schema::create('order_details', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->increments('id');
            $table->integer('order_id');
            $table->integer('product_id');
            $table->unsignedInteger('product_size_id')->nullable();
            $table->unsignedInteger('product_color_id')->nullable();
            $table->string('product_size', 255)->nullable();
            $table->string('product_color', 255)->nullable();
            $table->string('product_name', 255);
            $table->integer('purchase_price');
            $table->decimal('product_discount', 10, 2)->default(0.00);
            $table->integer('sale_price');
            $table->integer('qty');
            $table->string('image', 255);
            $table->string('image_color', 255)->nullable();
            $table->longText('photos')->nullable();
            $table->text('order_note')->nullable();
            $table->text('writing')->nullable();
            $table->string('product_sku', 255)->nullable();
            $table->decimal('additional_cost', 10, 2)->nullable();
            $table->decimal('addi_percentage', 10, 2)->nullable();
            $table->decimal('cod', 10, 2)->nullable();
            $table->decimal('cod_value', 10, 2)->nullable();
            $table->decimal('courier_cost', 10, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_details');
    }
};
