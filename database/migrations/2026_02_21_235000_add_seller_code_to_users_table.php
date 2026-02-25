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
        if (!Schema::hasColumn('users', 'seller_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('seller_code', 120)->nullable()->after('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'seller_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('seller_code');
            });
        }
    }
};

