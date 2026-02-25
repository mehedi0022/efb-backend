<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $subjectType = (string) $request->attributes->get('auth_subject_type', '');
        if ($subjectType !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        $requiredPermissions = collect(explode('|', $permissions))
            ->map(fn ($permission) => trim($permission))
            ->filter()
            ->values();

        if ($requiredPermissions->isEmpty()) {
            return $next($request);
        }

        $allowed = $requiredPermissions->contains(function (string $permission) use ($user) {
            return $user->can($permission);
        });

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this module.',
                'required_permissions' => $requiredPermissions,
            ], 403);
        }

        return $next($request);
    }
}
