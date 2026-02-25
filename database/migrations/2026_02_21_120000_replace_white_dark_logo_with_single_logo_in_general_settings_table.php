<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('general_settings', 'logo')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->string('logo', 255)->nullable()->after('name');
            });
        }

        if (Schema::hasColumn('general_settings', 'white_logo')) {
            DB::table('general_settings')
                ->whereNull('logo')
                ->whereNotNull('white_logo')
                ->update(['logo' => DB::raw('white_logo')]);
        }

        if (Schema::hasColumn('general_settings', 'dark_logo')) {
            DB::table('general_settings')
                ->whereNull('logo')
                ->whereNotNull('dark_logo')
                ->update(['logo' => DB::raw('dark_logo')]);
        }

        if (Schema::hasColumn('general_settings', 'white_logo')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->dropColumn('white_logo');
            });
        }

        if (Schema::hasColumn('general_settings', 'dark_logo')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->dropColumn('dark_logo');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('general_settings', 'white_logo')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->string('white_logo', 255)->nullable()->after('logo');
            });
        }

        if (!Schema::hasColumn('general_settings', 'dark_logo')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->string('dark_logo', 255)->nullable()->after('white_logo');
            });
        }

        if (Schema::hasColumn('general_settings', 'logo')) {
            DB::table('general_settings')
                ->whereNull('white_logo')
                ->whereNotNull('logo')
                ->update(['white_logo' => DB::raw('logo')]);

            DB::table('general_settings')
                ->whereNull('dark_logo')
                ->whereNotNull('logo')
                ->update(['dark_logo' => DB::raw('logo')]);

            Schema::table('general_settings', function (Blueprint $table) {
                $table->dropColumn('logo');
            });
        }
    }
};
