<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure orders table exists in the selected DB.
     */
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->increments('id');
            $table->string('ip_address', 255);
            $table->string('invoice_id', 55);
            $table->integer('amount');
            $table->integer('discount');
            $table->integer('shipping_charge');
            $table->integer('customer_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('district')->nullable();
            $table->string('order_status', 55);
            $table->text('note')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Keep down as no-op to avoid accidental data loss.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};

