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
            if (!Schema::hasColumn('orders', 'discount_type')) {
                $table->string('discount_type', 20)->default('fixed')->after('discount');
            }

            if (!Schema::hasColumn('orders', 'discount_value')) {
                $table->decimal('discount_value', 10, 2)->default(0)->after('discount_type');
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

            if (Schema::hasColumn('orders', 'discount_type')) {
                $columnsToDrop[] = 'discount_type';
            }

            if (Schema::hasColumn('orders', 'discount_value')) {
                $columnsToDrop[] = 'discount_value';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

