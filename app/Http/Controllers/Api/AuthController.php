<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\JwtException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone',
            'email' => 'nullable|email|max:255',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $customer = Customer::create([
            'name' => $request->string('name')->toString(),
            'slug' => Str::slug($request->string('name')->toString()) . '-' . Str::lower(Str::random(6)),
            'phone' => $request->string('phone')->toString(),
            'email' => $request->input('email'),
            'password' => Hash::make($request->string('password')->toString()),
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'status' => 'active',
            'verify' => 1,
        ]);

        $tokens = $this->jwtService->issueTokenPair('customer', $customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'customer' => $this->serializeCustomer($customer),
            'token' => $tokens['access_token'],
            ...$tokens,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $customer = Customer::where('phone', $request->string('phone')->toString())->first();
        if (!$customer || !Hash::check($request->string('password')->toString(), $customer->password)) {
            return $this->errorResponse('Invalid credentials.', 401, [
                'phone' => ['Invalid phone or password.'],
            ]);
        }

        if (($customer->status ?? 'active') !== 'active') {
            return $this->errorResponse('Account is not active.', 403);
        }

        $tokens = $this->jwtService->issueTokenPair('customer', $customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'customer' => $this->serializeCustomer($customer),
            'token' => $tokens['access_token'],
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
            'customer'
        );

        $customer = Customer::find($payload['sub']);
        if (!$customer) {
            return $this->errorResponse('Customer not found.', 401);
        }

        // Rotate refresh token on every refresh call.
        $this->jwtService->revokeByJti((string) $payload['jti'], (int) $payload['exp']);

        if ($request->bearerToken()) {
            try {
                $accessPayload = $this->jwtService->parseAndValidate($request->bearerToken(), 'access', 'customer');
                $this->jwtService->revokeByJti((string) $accessPayload['jti'], (int) $accessPayload['exp']);
            } catch (JwtException) {
                // Access token may already be expired at refresh time.
            }
        }

        $tokens = $this->jwtService->issueTokenPair('customer', $customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully.',
            'customer' => $this->serializeCustomer($customer),
            'token' => $tokens['access_token'],
            ...$tokens,
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        /** @var Customer|null $customer */
        $customer = $request->user();

        if (!$customer) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        return response()->json($this->serializeCustomer($customer));
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->bearerToken()) {
            try {
                $accessPayload = $this->jwtService->parseAndValidate($request->bearerToken(), 'access', 'customer');
                $this->jwtService->revokeByJti((string) $accessPayload['jti'], (int) $accessPayload['exp']);
            } catch (JwtException) {
                // Ignore invalid/expired access token, refresh token can still be revoked.
            }
        }

        $refreshToken = $request->input('refresh_token');
        if (is_string($refreshToken) && trim($refreshToken) !== '') {
            try {
                $refreshPayload = $this->jwtService->parseAndValidate($refreshToken, 'refresh', 'customer');
                $this->jwtService->revokeByJti((string) $refreshPayload['jti'], (int) $refreshPayload['exp']);
            } catch (JwtException) {
                // Ignore invalid refresh token during logout.
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'district' => $customer->district,
            'address' => $customer->address,
        ];
    }
}
