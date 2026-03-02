<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly RbacService $rbacService)
    {
    }

    public function index(Request $request)
    {
        $query = User::query()->with(['roles:id,name']);

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->keyword);
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('email', 'LIKE', '%' . $keyword . '%');
            });
        }

        $perPage = max(1, min((int) $request->get('per_page', 20), 100));
        $users = $query->orderByDesc('id')->paginate($perPage);

        $roles = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => collect($users->items())
                ->map(function (User $user) {
                    $user->loadMissing(['roles:id,name', 'permissions:id,name']);

                    return $this->rbacService->userData($user);
                })
                ->values(),
            'roles' => $roles,
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $user = User::query()
            ->with(['roles:id,name', 'permissions:id,name'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->rbacService->userData($user),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')
                    ->where(fn ($query) => $query->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
        ]);

        $role = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->findOrFail((int) $validated['role_id']);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
        ]);

        $user->syncRoles([$role->name]);
        $user->loadMissing(['roles:id,name', 'permissions:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully.',
            'data' => $this->rbacService->userData($user),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = User::query()->with(['roles:id,name', 'permissions:id,name'])->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')
                    ->where(fn ($query) => $query->where('guard_name', RbacService::ADMIN_GUARD)),
            ],
        ]);

        $role = Role::query()
            ->where('guard_name', RbacService::ADMIN_GUARD)
            ->findOrFail((int) $validated['role_id']);

        $user->name = $validated['name'];
        $user->email = strtolower($validated['email']);
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();

        $user->syncRoles([$role->name]);
        $user->refresh();
        $user->loadMissing(['roles:id,name', 'permissions:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully.',
            'data' => $this->rbacService->userData($user),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if ($authUser && (int) $authUser->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user = User::query()->with(['roles:id,name'])->findOrFail($id);
        $user->syncRoles([]);
        $user->syncPermissions([]);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully.',
        ]);
    }

    public function upsertSellerCode(Request $request, int $id)
    {
        $user = User::query()
            ->with(['roles:id,name', 'permissions:id,name'])
            ->findOrFail($id);

        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Seller code can only be managed for Super Admin users.',
            ], 422);
        }

        $validated = $request->validate(
            [
                'seller_code' => ['required', 'string', 'max:120', 'regex:/\\S/'],
                'security_code' => ['nullable', 'string', 'max:120'],
            ],
            [
                'seller_code.regex' => 'Seller code is required.',
            ]
        );

        $hasExistingSellerCode = filled($user->seller_code);
        if ($hasExistingSellerCode) {
            $securityCode = trim((string) ($validated['security_code'] ?? ''));
            if (!hash_equals('forgotcode', $securityCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid security code.',
                ], 422);
            }
        }

        $user->seller_code = trim((string) $validated['seller_code']);
        $user->save();
        $user->refresh();
        $user->loadMissing(['roles:id,name', 'permissions:id,name']);

        return response()->json([
            'success' => true,
            'message' => $hasExistingSellerCode
                ? 'Seller code updated successfully.'
                : 'Seller code added successfully.',
            'data' => $this->rbacService->userData($user),
        ]);
    }
}
