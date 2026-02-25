<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\RbacService;
use App\Support\RbacPermissionCatalog;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    public function __construct(private readonly RbacService $rbacService)
    {
    }

    public function index(Request $request)
    {
        $this->rbacService->syncDefaultPermissions();

        $query = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->withCount('roles')
            ->orderBy('name');

        if ($request->filled('keyword')) {
            $keyword = strtolower(trim((string) $request->keyword));
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . $keyword . '%']);
        }

        if ($request->filled('module')) {
            $module = RbacPermissionCatalog::normalizeSegment((string) $request->module);
            $query->whereRaw('LOWER(name) LIKE ?', [$module . '.%']);
        }

        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
        $permissions = $query->paginate($perPage);
        $modules = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->orderBy('name')
            ->pluck('name')
            ->map(function (string $permissionName) {
                return RbacPermissionCatalog::splitPermissionName($permissionName)['module'] ?? null;
            })
            ->filter(fn ($module) => is_string($module) && $module !== '')
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => collect($permissions->items())
                ->map(fn (Permission $permission) => $this->rbacService->permissionData($permission))
                ->values(),
            'modules' => $modules,
            'pagination' => [
                'total' => $permissions->total(),
                'per_page' => $permissions->perPage(),
                'current_page' => $permissions->currentPage(),
                'last_page' => $permissions->lastPage(),
                'from' => $permissions->firstItem(),
                'to' => $permissions->lastItem(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $permission = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->withCount('roles')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->rbacService->permissionData($permission),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:50'],
        ]);

        $permissionName = $this->rbacService->resolvePermissionName(
            (string) ($validated['name'] ?? ''),
            $validated['module'] ?? null,
            $validated['action'] ?? null
        );

        if ($permissionName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Permission name or module/action is required.',
            ], 422);
        }

        $alreadyExists = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->where('name', $permissionName)
            ->exists();
        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'Permission name already exists.',
            ], 422);
        }

        $permission = Permission::query()->create([
            'name' => $permissionName,
            'guard_name' => RbacService::ADMIN_GUARD,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission->loadCount('roles');

        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully.',
            'data' => $this->rbacService->permissionData($permission),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $permission = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:50'],
        ]);

        $permissionName = $this->rbacService->resolvePermissionName(
            (string) ($validated['name'] ?? ''),
            $validated['module'] ?? null,
            $validated['action'] ?? null
        );

        if ($permissionName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Permission name or module/action is required.',
            ], 422);
        }

        $alreadyExists = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->where('name', $permissionName)
            ->where('id', '!=', $permission->id)
            ->exists();
        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'Permission name already exists.',
            ], 422);
        }

        $permission->name = $permissionName;
        $permission->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission->refresh();
        $permission->loadCount('roles');

        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully.',
            'data' => $this->rbacService->permissionData($permission),
        ]);
    }

    public function destroy(int $id)
    {
        $permission = Permission::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->findOrFail($id);

        $permission->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully.',
        ]);
    }
}
