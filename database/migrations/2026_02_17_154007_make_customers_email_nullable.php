<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            DB::statement('ALTER TABLE `customers` MODIFY `email` VARCHAR(55) NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            DB::statement('ALTER TABLE `customers` MODIFY `email` VARCHAR(55) NOT NULL');
        }
    }
};
