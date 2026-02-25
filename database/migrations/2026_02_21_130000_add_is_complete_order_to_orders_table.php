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
        if (!Schema::hasColumn('orders', 'is_complete_order')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('is_complete_order')->default(0)->after('order_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'is_complete_order')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('is_complete_order');
            });
        }
    }
};
