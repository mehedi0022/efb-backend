<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\RbacService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminUsersSeeder extends Seeder
{
    /**
     * Seed fixed super admin users for bootstrap access.
     */
    public function run(): void
    {
        $rbacService = app(RbacService::class);
        $permissions = $rbacService->syncDefaultPermissions()->pluck('name')->all();

        $superAdminRole = Role::findOrCreate('super-admin', RbacService::ADMIN_GUARD);
        if (!empty($permissions)) {
            $superAdminRole->syncPermissions($permissions);
        }

        $users = [
            [
                'name' => 'MK Super Admin',
                'email' => 'mk@gmail.com',
            ],
            [
                'name' => 'Admin Super Admin',
                'email' => 'admin@gmail.com',
            ],
        ];

        foreach ($users as $seedUser) {
            $user = User::query()->updateOrCreate(
                ['email' => strtolower($seedUser['email'])],
                [
                    'name' => $seedUser['name'],
                    'password' => Hash::make('12345678'),
                ]
            );

            $user->syncRoles([$superAdminRole->name]);
        }
    }
}
