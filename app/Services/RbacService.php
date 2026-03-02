<?php

namespace App\Services;

use App\Models\User;
use App\Support\RbacPermissionCatalog;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacService
{
    public const ADMIN_GUARD = 'web';

    /**
     * Ensure baseline module permissions exist.
     *
     * @return \Illuminate\Support\Collection<int, Permission>
     */
    public function syncDefaultPermissions(): Collection
    {
        $permissionNames = RbacPermissionCatalog::allPermissionNames();

        foreach ($permissionNames as $permissionName) {
            Permission::findOrCreate($permissionName, self::ADMIN_GUARD);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::query()
            ->where('guard_name', self::ADMIN_GUARD)
            ->orderBy('name')
            ->get();
    }

    public function resolvePermissionName(string $rawName = '', ?string $module = null, ?string $action = null): string
    {
        $module = $module !== null ? RbacPermissionCatalog::normalizeSegment($module) : '';
        $action = $action !== null ? RbacPermissionCatalog::normalizeSegment($action) : '';

        if ($module !== '' && $action !== '') {
            return RbacPermissionCatalog::buildPermissionName($module, $action);
        }

        return RbacPermissionCatalog::normalizeSegment($rawName);
    }

    public function roleData(Role $role): array
    {
        $permissionNames = $role->permissions
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
            'permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'permissions' => $permissionNames,
            'permissions_count' => $role->permissions->count(),
            'users_count' => isset($role->users_count) ? (int) $role->users_count : $role->users()->count(),
        ];
    }

    public function permissionData(Permission $permission): array
    {
        $parts = RbacPermissionCatalog::splitPermissionName($permission->name);

        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'module' => $parts['module'],
            'action' => $parts['action'],
            'guard_name' => $permission->guard_name,
            'roles_count' => isset($permission->roles_count)
                ? (int) $permission->roles_count
                : $permission->roles()->count(),
            'created_at' => $permission->created_at,
            'updated_at' => $permission->updated_at,
        ];
    }

    public function userData(User $user): array
    {
        $roleNames = $user->roles
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values();

        $permissionNames = $user->getAllPermissions()
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'seller_code' => $user->seller_code,
            'image' => $user->image ?? null,
            'roles' => $roleNames,
            'role_ids' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'primary_role' => $roleNames->first(),
            'permissions' => $permissionNames->values(),
            'permissions_count' => $permissionNames->count(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
