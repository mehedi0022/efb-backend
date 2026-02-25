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
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id');
            $table->string('type', 55)->nullable();
            $table->string('app_key', 155)->nullable();
            $table->string('app_secret', 155)->nullable();
            $table->string('username', 55)->nullable();
            $table->string('password', 55)->nullable();
            $table->string('base_url', 99)->nullable();
            $table->string('success_url', 155)->nullable();
            $table->string('return_url', 155)->nullable();
            $table->string('prefix', 25)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
