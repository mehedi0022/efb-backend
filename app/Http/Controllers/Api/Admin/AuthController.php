<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\JwtException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly RbacService $rbacService
    )
    {
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim($request->string('email')->toString()));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if (!$user || !Hash::check($request->string('password')->toString(), $user->password)) {
            return $this->errorResponse('Invalid credentials.', 401, [
                'email' => ['Invalid email or password.'],
            ]);
        }

        $this->ensureBootstrapRoleIfNeeded($user);

        if (!$user->roles()->exists()) {
            return $this->errorResponse('No role is assigned to this employee. Please contact a super admin.', 403);
        }

        $tokens = $this->jwtService->issueTokenPair('admin', $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $tokens['access_token'],
            'user' => $this->serializeAdmin($user),
            ...$tokens,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $payload = $this->jwtService->parseAndValidate(
            $request->string('refresh_token')->toString(),
            'refresh',
            'admin'
        );

        $user = User::find($payload['sub']);
        if (!$user) {
            return $this->errorResponse('User not found.', 401);
        }

        $this->jwtService->revokeByJti((string) $payload['jti'], (int) $payload['exp']);

        if ($request->bearerToken()) {
            try {
                $accessPayload = $this->jwtService->parseAndValidate($request->bearerToken(), 'access', 'admin');
                $this->jwtService->revokeByJti((string) $accessPayload['jti'], (int) $accessPayload['exp']);
            } catch (JwtException) {
                // Access token may already be expired at refresh time.
            }
        }

        $tokens = $this->jwtService->issueTokenPair('admin', $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully.',
            'token' => $tokens['access_token'],
            'user' => $this->serializeAdmin($user),
            ...$tokens,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->bearerToken()) {
            try {
                $accessPayload = $this->jwtService->parseAndValidate($request->bearerToken(), 'access', 'admin');
                $this->jwtService->revokeByJti((string) $accessPayload['jti'], (int) $accessPayload['exp']);
            } catch (JwtException) {
                // Ignore invalid/expired access token, refresh token can still be revoked.
            }
        }

        $refreshToken = $request->input('refresh_token');
        if (is_string($refreshToken) && trim($refreshToken) !== '') {
            try {
                $refreshPayload = $this->jwtService->parseAndValidate($refreshToken, 'refresh', 'admin');
                $this->jwtService->revokeByJti((string) $refreshPayload['jti'], (int) $refreshPayload['exp']);
            } catch (JwtException) {
                // Ignore invalid refresh token during logout.
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        return response()->json([
            'success' => true,
            'user' => $this->serializeAdmin($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'image' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ]);

        $user->name = trim((string) $validated['name']);
        $user->email = strtolower(trim((string) $validated['email']));

        if ($request->hasFile('image')) {
            $uploadDirectory = $this->ensureProfileUploadDirectory();
            $newImagePath = $this->storeUploadedProfileImage($request->file('image'), $uploadDirectory);
            $this->deleteUploadedProfileImage($user->image);
            $user->image = $newImagePath;
        }

        $user->save();
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => $this->serializeAdmin($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        if (!Hash::check((string) $validated['current_password'], $user->password)) {
            return $this->errorResponse('Current password is incorrect.', 422, [
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make((string) $validated['password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    private function serializeAdmin(User $user): array
    {
        $user->loadMissing(['roles:id,name', 'permissions:id,name']);

        $data = $this->rbacService->userData($user);
        $data['role'] = $data['primary_role'] ?? 'unassigned';

        return $data;
    }

    private function ensureBootstrapRoleIfNeeded(User $user): void
    {
        if ($user->roles()->exists()) {
            return;
        }

        $firstUserId = (int) User::query()->orderBy('id')->value('id');
        if ($firstUserId <= 0 || $firstUserId !== (int) $user->id) {
            return;
        }

        $permissions = $this->rbacService->syncDefaultPermissions()->pluck('name')->all();
        $superAdminRole = Role::findOrCreate('super-admin', RbacService::ADMIN_GUARD);

        if (!empty($permissions)) {
            $superAdminRole->syncPermissions($permissions);
        }

        $user->syncRoles([$superAdminRole->name]);
    }

    private function ensureProfileUploadDirectory(): string
    {
        $absolutePath = public_path('uploads/users');

        if (!File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }

        return $absolutePath;
    }

    private function storeUploadedProfileImage(UploadedFile $image, string $uploadDirectory): string
    {
        $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = strtolower((string) ($image->getClientOriginalExtension() ?: $image->guessExtension() ?: 'img'));
        $safeName = Str::slug($originalName ?: 'profile');
        $fileName = time() . '-' . ($safeName ?: 'profile') . '-' . Str::random(6) . '.' . $extension;

        $image->move($uploadDirectory, $fileName);

        return 'uploads/users/' . $fileName;
    }

    private function deleteUploadedProfileImage(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (preg_match('/^https?:\\/\\//i', $path)) {
            return;
        }

        $absolutePath = public_path(ltrim($path, '/'));
        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }
}
