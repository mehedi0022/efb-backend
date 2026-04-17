<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\ShippingCharge;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (!User::query()->exists()) {
            $this->call([
                SuperAdminUsersSeeder::class,
            ]);
        }

        if (!ShippingCharge::query()->exists()) {
            $this->call([
                ShippingChargeSeeder::class,
            ]);
        }
    }
}
