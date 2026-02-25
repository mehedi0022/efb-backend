<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\RbacService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(private readonly RbacService $rbacService)
    {
    }

    public function index(Request $request)
    {
        $this->rbacService->syncDefaultPermissions();

        $query = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->with(['permissions:id,name'])
            ->withCount('users')
            ->orderBy('name');

        if ($request->filled('keyword')) {
            $query->where('name', 'LIKE', '%' . trim((string) $request->keyword) . '%');
        }

        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
        $roles = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($roles->items())
                ->map(fn (Role $role) => $this->rbacService->roleData($role))
                ->values(),
            'permissions' => Permission::query()
                ->where('guard_name', RbacService::ADMIN_GUARD)
                ->orderBy('name')
                ->get()
                ->map(fn (Permission $permission) => $this->rbacService->permissionData($permission))
                ->values(),
            'pagination' => [
                'total' => $roles->total(),
                'per_page' => $roles->perPage(),
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'from' => $roles->firstItem(),
                'to' => $roles->lastItem(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $role = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->with(['permissions:id,name'])
            ->withCount('users')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->rbacService->roleData($role),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => [
                'integer',
                Rule::exists('permissions', 'id')
                    ->where(fn ($query) => $query->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
        ]);

        $role = Role::query()->create([
            'name' => strtolower(trim((string) $validated['name'])),
            'guard_name' => RbacService::ADMIN_GUARD,
        ]);

        $permissionIds = collect($validated['permission_ids'] ?? [])->map(fn ($id) => (int) $id)->values();
        if ($permissionIds->isNotEmpty()) {
            $role->syncPermissions(
                Permission::query()
                    ->where('guard_name', RbacService::ADMIN_GUARD)
                    ->whereIn('id', $permissionIds)
                    ->pluck('name')
                    ->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role->loadMissing(['permissions:id,name']);
        $role->loadCount('users');

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $this->rbacService->roleData($role),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $role = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->with(['permissions:id,name'])
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->ignore($role->id)
                    ->where(fn ($q) => $q->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => [
                'integer',
                Rule::exists('permissions', 'id')
                    ->where(fn ($query) => $query->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
        ]);

        $nextRoleName = strtolower(trim((string) $validated['name']));
        if ($role->name === 'super-admin' && $nextRoleName !== 'super-admin') {
            return response()->json([
                'success' => false,
                'message' => 'The super-admin role name cannot be changed.',
            ], 422);
        }

        $role->name = $nextRoleName;
        $role->save();

        if (array_key_exists('permission_ids', $validated)) {
            $permissionNames = Permission::query()
                ->where('guard_name', RbacService::ADMIN_GUARD)
                ->whereIn('id', collect($validated['permission_ids'])->map(fn ($id) => (int) $id))
                ->pluck('name')
                ->all();

            $role->syncPermissions($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role->refresh();
        $role->loadMissing(['permissions:id,name']);
        $role->loadCount('users');

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $this->rbacService->roleData($role),
        ]);
    }

    public function destroy(int $id)
    {
        $role = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->withCount('users')
            ->findOrFail($id);

        if ($role->name === 'super-admin') {
            return response()->json([
                'success' => false,
                'message' => 'The super-admin role cannot be deleted.',
            ], 422);
        }

        if ((int) $role->users_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This role is assigned to one or more employees and cannot be deleted.',
            ], 422);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }
}
