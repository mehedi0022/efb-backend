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
            if (!Schema::hasColumn('general_settings', 'description')) {
                $table->text('description')->nullable();
            }

            if (!Schema::hasColumn('general_settings', 'header_bg_color')) {
                $table->string('header_bg_color', 20)->nullable();
            }

            if (!Schema::hasColumn('general_settings', 'fb_link')) {
                $table->string('fb_link', 255)->nullable();
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

            if (Schema::hasColumn('general_settings', 'description')) {
                $columnsToDrop[] = 'description';
            }

            if (Schema::hasColumn('general_settings', 'header_bg_color')) {
                $columnsToDrop[] = 'header_bg_color';
            }

            if (Schema::hasColumn('general_settings', 'fb_link')) {
                $columnsToDrop[] = 'fb_link';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
