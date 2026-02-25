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
        Schema::table('general_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('general_settings', 'hotline')) {
                $table->string('hotline', 50)->nullable()->after('courier_charge');
            }

            if (!Schema::hasColumn('general_settings', 'whatsapp')) {
                $table->string('whatsapp', 50)->nullable()->after('hotline');
            }

            if (!Schema::hasColumn('general_settings', 'messenger')) {
                $table->string('messenger', 255)->nullable()->after('whatsapp');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('general_settings', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('general_settings', 'hotline')) {
                $columnsToDrop[] = 'hotline';
            }

            if (Schema::hasColumn('general_settings', 'whatsapp')) {
                $columnsToDrop[] = 'whatsapp';
            }

            if (Schema::hasColumn('general_settings', 'messenger')) {
                $columnsToDrop[] = 'messenger';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
