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
        Schema::table('create_pages', function (Blueprint $table) {
            if (!Schema::hasColumn('create_pages', 'footer_section')) {
                $table->string('footer_section', 20)->default('useful')->after('status');
            }

            if (!Schema::hasColumn('create_pages', 'footer_sort_order')) {
                $table->unsignedInteger('footer_sort_order')->default(0)->after('footer_section');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('create_pages', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('create_pages', 'footer_sort_order')) {
                $columnsToDrop[] = 'footer_sort_order';
            }

            if (Schema::hasColumn('create_pages', 'footer_section')) {
                $columnsToDrop[] = 'footer_section';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
