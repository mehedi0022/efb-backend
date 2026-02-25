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
        Schema::create('customers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->increments('id');
            $table->string('ip_address', 255);
            $table->string('name', 155);
            $table->string('slug', 155);
            $table->string('phone', 55);
            $table->string('email', 55);
            $table->double('balance', 8, 2)->default(0.00);
            $table->string('district', 255)->nullable();
            $table->string('area', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->integer('verify')->nullable();
            $table->string('image', 255)->default('public/uploads/default/user.png');
            $table->string('password', 255);
            $table->string('remember_token', 255)->nullable();
            $table->string('status', 55);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
