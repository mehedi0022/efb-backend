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
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'youtube_video_url')) {
                $table->string('youtube_video_url', 1000)->nullable()->after('description');
            }

            if (!Schema::hasColumn('products', 'facebook_video_url')) {
                $table->string('facebook_video_url', 1000)->nullable()->after('youtube_video_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('products', 'facebook_video_url')) {
                $columnsToDrop[] = 'facebook_video_url';
            }

            if (Schema::hasColumn('products', 'youtube_video_url')) {
                $columnsToDrop[] = 'youtube_video_url';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
