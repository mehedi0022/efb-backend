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
        Schema::table('incomplete_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('incomplete_orders', 'cart_id')) {
                $table->string('cart_id', 64)->nullable()->after('phone');
                $table->index('cart_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incomplete_orders', function (Blueprint $table) {
            if (Schema::hasColumn('incomplete_orders', 'cart_id')) {
                $table->dropIndex(['cart_id']);
                $table->dropColumn('cart_id');
            }
        });
    }
};
