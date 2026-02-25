<?php

namespace App\Http\Middleware;

use App\Exceptions\JwtException;
use App\Models\Customer;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtOptionalAuthenticate
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    public function handle(Request $request, Closure $next, ?string $subjectType = null): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return $next($request);
        }

        try {
            $payload = $this->jwtService->parseAndValidate($token, 'access', $subjectType);
            $user = $this->resolveUser($payload);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user not found.',
                ], 401);
            }

            $request->attributes->set('jwt_payload', $payload);
            $request->attributes->set('auth_subject_type', $payload['subject_type']);
            $request->attributes->set('auth_token', $token);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        } catch (JwtException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status());
        }
    }

    private function resolveUser(array $payload): User|Customer|null
    {
        $subjectId = $payload['sub'] ?? null;
        $subjectType = $payload['subject_type'] ?? null;

        return match ($subjectType) {
            'admin' => User::query()->find($subjectId),
            'customer' => Customer::query()->find($subjectId),
            default => null,
        };
    }
}
