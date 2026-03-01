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
        if (!Schema::hasTable('courierapis')) {
            return;
        }

        if (Schema::hasColumn('courierapis', 'api_key')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `api_key` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'secret_key')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `secret_key` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'client_id')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `client_id` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'client_secret')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `client_secret` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'username')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `username` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'password')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `password` TEXT NULL');
        }

        if (Schema::hasColumn('courierapis', 'token')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `token` LONGTEXT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('courierapis')) {
            return;
        }

        if (Schema::hasColumn('courierapis', 'api_key')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `api_key` VARCHAR(155) NULL');
        }

        if (Schema::hasColumn('courierapis', 'secret_key')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `secret_key` VARCHAR(155) NULL');
        }

        if (Schema::hasColumn('courierapis', 'client_id')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `client_id` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('courierapis', 'client_secret')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `client_secret` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('courierapis', 'username')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `username` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('courierapis', 'password')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `password` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('courierapis', 'token')) {
            DB::statement('ALTER TABLE `courierapis` MODIFY `token` VARCHAR(350) NULL');
        }
    }
};

