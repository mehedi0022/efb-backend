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
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'courier_name')) {
                $table->string('courier_name', 50)->nullable()->after('order_status');
            }

            if (!Schema::hasColumn('orders', 'courier_status')) {
                $table->string('courier_status', 60)->nullable()->after('courier_name');
            }

            if (!Schema::hasColumn('orders', 'courier_order_id')) {
                $table->string('courier_order_id', 120)->nullable()->after('courier_status');
            }

            if (!Schema::hasColumn('orders', 'courier_synced_at')) {
                $table->timestamp('courier_synced_at')->nullable()->after('courier_order_id');
            }

            if (!Schema::hasColumn('orders', 'courier_sync_error')) {
                $table->text('courier_sync_error')->nullable()->after('courier_synced_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('orders', 'courier_name')) {
                $columnsToDrop[] = 'courier_name';
            }

            if (Schema::hasColumn('orders', 'courier_status')) {
                $columnsToDrop[] = 'courier_status';
            }

            if (Schema::hasColumn('orders', 'courier_order_id')) {
                $columnsToDrop[] = 'courier_order_id';
            }

            if (Schema::hasColumn('orders', 'courier_synced_at')) {
                $columnsToDrop[] = 'courier_synced_at';
            }

            if (Schema::hasColumn('orders', 'courier_sync_error')) {
                $columnsToDrop[] = 'courier_sync_error';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

