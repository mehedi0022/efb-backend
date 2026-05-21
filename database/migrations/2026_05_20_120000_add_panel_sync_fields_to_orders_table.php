<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'panel_order_id')) {
                $table->unsignedBigInteger('panel_order_id')->nullable()->after('invoice_id');
            }
            if (!Schema::hasColumn('orders', 'panel_order_no')) {
                $table->string('panel_order_no', 64)->nullable()->after('panel_order_id');
            }
            if (!Schema::hasColumn('orders', 'panel_sync_status')) {
                $table->string('panel_sync_status', 20)->default('pending')->after('panel_order_no');
            }
            if (!Schema::hasColumn('orders', 'panel_sync_error')) {
                $table->text('panel_sync_error')->nullable()->after('panel_sync_status');
            }
            if (!Schema::hasColumn('orders', 'panel_synced_at')) {
                $table->timestamp('panel_synced_at')->nullable()->after('panel_sync_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'panel_order_id',
                'panel_order_no',
                'panel_sync_status',
                'panel_sync_error',
                'panel_synced_at',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $drop[] = $column;
                }
            }
            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};

