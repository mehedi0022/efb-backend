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
            if (!Schema::hasColumn('general_settings', 'button_primary_text_color')) {
                $table->string('button_primary_text_color', 20)->nullable();
            }

            if (!Schema::hasColumn('general_settings', 'button_secondary_bg_color')) {
                $table->string('button_secondary_bg_color', 20)->nullable();
            }

            if (!Schema::hasColumn('general_settings', 'button_secondary_hover_color')) {
                $table->string('button_secondary_hover_color', 20)->nullable();
            }

            if (!Schema::hasColumn('general_settings', 'button_secondary_text_color')) {
                $table->string('button_secondary_text_color', 20)->nullable();
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

            if (Schema::hasColumn('general_settings', 'button_primary_text_color')) {
                $columnsToDrop[] = 'button_primary_text_color';
            }

            if (Schema::hasColumn('general_settings', 'button_secondary_bg_color')) {
                $columnsToDrop[] = 'button_secondary_bg_color';
            }

            if (Schema::hasColumn('general_settings', 'button_secondary_hover_color')) {
                $columnsToDrop[] = 'button_secondary_hover_color';
            }

            if (Schema::hasColumn('general_settings', 'button_secondary_text_color')) {
                $columnsToDrop[] = 'button_secondary_text_color';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
