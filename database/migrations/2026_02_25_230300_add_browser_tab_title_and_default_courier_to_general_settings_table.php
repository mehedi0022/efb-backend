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
            if (!Schema::hasColumn('general_settings', 'browser_tab_title')) {
                $table->string('browser_tab_title', 120)->nullable()->after('name');
            }

            if (!Schema::hasColumn('general_settings', 'default_courier')) {
                $table->string('default_courier', 30)->nullable()->after('courier_charge');
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

            if (Schema::hasColumn('general_settings', 'browser_tab_title')) {
                $columnsToDrop[] = 'browser_tab_title';
            }

            if (Schema::hasColumn('general_settings', 'default_courier')) {
                $columnsToDrop[] = 'default_courier';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

