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
        Schema::table('social_media', function (Blueprint $table) {
            if (!Schema::hasColumn('social_media', 'url')) {
                $table->string('url', 255)->nullable()->after('icon');
            }

            if (!Schema::hasColumn('social_media', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_media', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('social_media', 'sort_order')) {
                $columnsToDrop[] = 'sort_order';
            }

            if (Schema::hasColumn('social_media', 'url')) {
                $columnsToDrop[] = 'url';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
