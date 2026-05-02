<?php

namespace Database\Seeders;

use App\Models\ShippingCharge;
use Illuminate\Database\Seeder;

class ShippingChargeSeeder extends Seeder
{
    /**
     * Seed default shipping charges only when the table is empty.
     */
    public function run(): void
    {
        if (ShippingCharge::query()->exists()) {
            return;
        }

        ShippingCharge::query()->insert([
            [
                'name' => 'Inside Dhaka',
                'amount' => 70,
                'status' => '1',
                'front_view' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Outside Dhaka',
                'amount' => 120,
                'status' => '1',
                'front_view' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
