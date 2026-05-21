<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            if (!Schema::hasColumn('order_details', 'panel_product_id')) {
                $table->unsignedBigInteger('panel_product_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('order_details', 'panel_variant_id')) {
                $table->unsignedBigInteger('panel_variant_id')->nullable()->after('panel_product_id');
            }
            if (!Schema::hasColumn('order_details', 'panel_seller_product_id')) {
                $table->unsignedBigInteger('panel_seller_product_id')->nullable()->after('panel_variant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'panel_product_id',
                'panel_variant_id',
                'panel_seller_product_id',
            ] as $column) {
                if (Schema::hasColumn('order_details', $column)) {
                    $drop[] = $column;
                }
            }
            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};

