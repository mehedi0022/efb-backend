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
        Schema::table('courierapis', function (Blueprint $table) {
            if (!Schema::hasColumn('courierapis', 'client_id')) {
                $table->string('client_id', 255)->nullable()->after('secret_key');
            }

            if (!Schema::hasColumn('courierapis', 'client_secret')) {
                $table->string('client_secret', 255)->nullable()->after('client_id');
            }

            if (!Schema::hasColumn('courierapis', 'username')) {
                $table->string('username', 255)->nullable()->after('client_secret');
            }

            if (!Schema::hasColumn('courierapis', 'password')) {
                $table->string('password', 255)->nullable()->after('username');
            }

            if (!Schema::hasColumn('courierapis', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courierapis', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('courierapis', 'client_id')) {
                $columnsToDrop[] = 'client_id';
            }

            if (Schema::hasColumn('courierapis', 'client_secret')) {
                $columnsToDrop[] = 'client_secret';
            }

            if (Schema::hasColumn('courierapis', 'username')) {
                $columnsToDrop[] = 'username';
            }

            if (Schema::hasColumn('courierapis', 'password')) {
                $columnsToDrop[] = 'password';
            }

            if (Schema::hasColumn('courierapis', 'token_expires_at')) {
                $columnsToDrop[] = 'token_expires_at';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

