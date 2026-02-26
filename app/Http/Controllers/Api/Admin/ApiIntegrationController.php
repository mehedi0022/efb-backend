<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courierapi;
use App\Models\GeneralSetting;
use App\Models\PaymentGateway;
use App\Models\SmsGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApiIntegrationController extends Controller
{
    private const PATHAO_DEFAULT_BASE_URL = 'https://api-hermes.pathao.com';
    private const PATHAO_ISSUE_TOKEN_PATH = '/aladdin/api/v1/issue-token';

    public function paymentIndex()
    {
        $bkash = PaymentGateway::where('type', 'bkash')->first();
        $shurjopay = PaymentGateway::where('type', 'shurjopay')->first();

        return response()->json([
            'success' => true,
            'bkash' => $bkash,
            'shurjopay' => $shurjopay,
        ]);
    }

    public function paymentUpdate(Request $request, $id)
    {
        $gateway = PaymentGateway::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $gateway->update($data);

        return response()->json(['success' => true, 'data' => $gateway]);
    }

    public function smsIndex()
    {
        $sms = SmsGateway::first();
        return response()->json(['success' => true, 'data' => $sms]);
    }

    public function smsUpdate(Request $request, $id)
    {
        $sms = SmsGateway::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->status ? 1 : 0;
        $data['order'] = $request->order ? 1 : 0;
        $data['forget_pass'] = $request->forget_pass ? 1 : 0;
        $data['password_g'] = $request->password_g ? 1 : 0;
        $sms->update($data);

        return response()->json(['success' => true, 'data' => $sms]);
    }

    public function courierIndex()
    {
        [$pathao, $steadfast] = $this->resolveOrCreateCourierConfigs();
        $activeCourier = $this->resolveActiveCourier($pathao, $steadfast);

        return response()->json([
            'success' => true,
            'active_courier' => $activeCourier,
            'pathao' => $this->formatCourierConfig($pathao),
            'steadfast' => $this->formatCourierConfig($steadfast),
            'couriers' => [
                'pathao' => $this->formatCourierConfig($pathao),
                'steadfast' => $this->formatCourierConfig($steadfast),
            ],
        ]);
    }

    public function courierUpdate(Request $request, $id)
    {
        /** @var Courierapi $courier */
        $courier = Courierapi::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => 'nullable|boolean',
            'url' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:255',
            'secret_key' => 'nullable|string|max:255',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'token' => 'nullable|string|max:500',
            'active_courier' => 'nullable|in:pathao,steadfast',
            'refresh_token' => 'nullable|boolean',
        ]);

        try {
            $payload = [];
            if (array_key_exists('status', $validated)) {
                $payload['status'] = $request->boolean('status') ? 1 : 0;
            }
            if (array_key_exists('url', $validated)) {
                $payload['url'] = trim((string) $validated['url']) ?: null;
            }

            $this->assignSensitiveFieldIfProvided($payload, $validated, 'api_key');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'secret_key');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'client_id');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'client_secret');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'username');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'password');
            $this->assignSensitiveFieldIfProvided($payload, $validated, 'token');

            $courier->fill($payload);
            $this->refreshCourierTokenIfApplicable($courier, $request);
            $courier->save();

            $activeCourier = $validated['active_courier'] ?? null;
            if ($activeCourier !== null && $activeCourier !== '') {
                $this->persistActiveCourier($activeCourier);
            } elseif (($payload['status'] ?? null) === 1) {
                $this->persistActiveCourier((string) $courier->type);
                $activeCourier = (string) $courier->type;
            } else {
                $activeCourier = $this->resolveActiveCourier(
                    $courier->type === 'pathao' ? $courier : Courierapi::query()->where('type', 'pathao')->first(),
                    $courier->type === 'steadfast' ? $courier : Courierapi::query()->where('type', 'steadfast')->first()
                );
            }

            return response()->json([
                'success' => true,
                'message' => ucfirst((string) $courier->type) . ' configuration saved successfully.',
                'active_courier' => $activeCourier,
                'data' => $this->formatCourierConfig($courier),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Failed to update courier configuration.',
            ], 422);
        }
    }

    public function getPathaoToken(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'url' => 'nullable|string|max:255',
        ]);

        try {
            $tokenResponse = $this->requestPathaoToken(
                clientId: trim((string) $validated['client_id']),
                clientSecret: trim((string) $validated['client_secret']),
                username: trim((string) $validated['username']),
                password: trim((string) $validated['password']),
                baseUrl: isset($validated['url']) ? trim((string) $validated['url']) : null
            );

            return response()->json([
                'success' => true,
                'token' => $tokenResponse['token'],
                'expires_at' => $tokenResponse['expires_at']?->toDateTimeString(),
                'expires_in' => $tokenResponse['expires_in'],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Pathao token generation failed.',
            ], 422);
        }
    }

    /**
     * @return array{0: Courierapi, 1: Courierapi}
     */
    private function resolveOrCreateCourierConfigs(): array
    {
        $pathao = Courierapi::query()->firstOrCreate(
            ['type' => 'pathao'],
            [
                'status' => 0,
                'url' => self::PATHAO_DEFAULT_BASE_URL,
            ]
        );

        $steadfast = Courierapi::query()->firstOrCreate(
            ['type' => 'steadfast'],
            [
                'status' => 0,
                'url' => null,
            ]
        );

        return [$pathao, $steadfast];
    }

    private function resolveActiveCourier(?Courierapi $pathao, ?Courierapi $steadfast): ?string
    {
        $configured = strtolower(trim((string) GeneralSetting::query()->orderByDesc('id')->value('default_courier')));
        if (in_array($configured, ['pathao', 'steadfast'], true)) {
            return $configured;
        }

        if ((int) ($pathao?->status ?? 0) === 1) {
            return 'pathao';
        }

        if ((int) ($steadfast?->status ?? 0) === 1) {
            return 'steadfast';
        }

        return null;
    }

    private function persistActiveCourier(string $courier): void
    {
        $normalized = strtolower(trim($courier));
        if (!in_array($normalized, ['pathao', 'steadfast'], true)) {
            return;
        }

        $setting = GeneralSetting::query()->orderByDesc('id')->first();
        if (!$setting) {
            return;
        }

        $setting->default_courier = $normalized;
        $setting->save();
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function assignSensitiveFieldIfProvided(array &$target, array $source, string $field): void
    {
        if (!array_key_exists($field, $source)) {
            return;
        }

        $value = trim((string) ($source[$field] ?? ''));
        if ($value === '') {
            return;
        }

        $target[$field] = $value;
    }

    private function refreshCourierTokenIfApplicable(Courierapi $courier, Request $request): void
    {
        $type = strtolower(trim((string) $courier->type));

        if ($type === 'pathao') {
            $hasAllCredentials = $this->hasPathaoCredentials($courier);
            if (!$hasAllCredentials) {
                return;
            }

            $token = trim((string) ($courier->token ?? ''));
            $forceRefresh = $request->boolean('refresh_token') || $token === '' || $courier->tokenExpired();
            if (!$forceRefresh) {
                return;
            }

            $tokenResponse = $this->requestPathaoToken(
                clientId: trim((string) $courier->client_id),
                clientSecret: trim((string) $courier->client_secret),
                username: trim((string) $courier->username),
                password: trim((string) $courier->password),
                baseUrl: trim((string) ($courier->url ?: self::PATHAO_DEFAULT_BASE_URL))
            );

            $courier->token = $tokenResponse['token'];
            $courier->token_expires_at = $tokenResponse['expires_at'];
            return;
        }

        if ($type === 'steadfast') {
            $token = trim((string) ($courier->token ?? ''));
            if ($token !== '') {
                return;
            }

            $apiKey = trim((string) ($courier->api_key ?? ''));
            if ($apiKey !== '') {
                $courier->token = $apiKey;
            }
        }
    }

    private function hasPathaoCredentials(Courierapi $courier): bool
    {
        return trim((string) ($courier->client_id ?? '')) !== ''
            && trim((string) ($courier->client_secret ?? '')) !== ''
            && trim((string) ($courier->username ?? '')) !== ''
            && trim((string) ($courier->password ?? '')) !== '';
    }

    /**
     * @return array{token:string,expires_at:Carbon|null,expires_in:int|null}
     */
    private function requestPathaoToken(
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        ?string $baseUrl = null
    ): array {
        if (
            trim($clientId) === ''
            || trim($clientSecret) === ''
            || trim($username) === ''
            || trim($password) === ''
        ) {
            throw ValidationException::withMessages([
                'credentials' => ['Pathao requires client ID, client secret, username and password.'],
            ]);
        }

        $endpoint = $this->resolvePathaoTokenEndpoint($baseUrl);
        $response = Http::acceptJson()->asJson()->post($endpoint, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            $errorMessage = $this->resolvePathaoErrorMessage($response);
            throw ValidationException::withMessages([
                'token' => [$errorMessage],
            ]);
        }

        $data = $response->json();
        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            throw ValidationException::withMessages([
                'token' => ['Pathao did not return a valid access token.'],
            ]);
        }

        $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in'])
            ? max(60, (int) $data['expires_in'])
            : null;

        $expiresAt = $expiresIn
            ? now()->addSeconds($expiresIn)
            : now()->addHours(6);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn,
        ];
    }

    private function resolvePathaoTokenEndpoint(?string $baseUrl = null): string
    {
        $candidate = trim((string) ($baseUrl ?? self::PATHAO_DEFAULT_BASE_URL));
        if ($candidate === '') {
            $candidate = self::PATHAO_DEFAULT_BASE_URL;
        }

        if (preg_match('/\/issue-token\/?$/i', $candidate)) {
            return rtrim($candidate, '/');
        }

        $normalizedBase = rtrim($candidate, '/');
        return $normalizedBase . self::PATHAO_ISSUE_TOKEN_PATH;
    }

    private function resolvePathaoErrorMessage(Response $response): string
    {
        $message = $response->json('message');
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        $errors = $response->json('errors');
        if (is_array($errors) && !empty($errors)) {
            $flatErrors = collect($errors)
                ->flatten()
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();

            if (!empty($flatErrors)) {
                return implode(' ', $flatErrors);
            }
        }

        $rawBody = trim((string) $response->body());
        if ($rawBody !== '') {
            return mb_substr($rawBody, 0, 300);
        }

        return 'Pathao token request failed.';
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCourierConfig(Courierapi $courier): array
    {
        return [
            'id' => (int) $courier->id,
            'type' => (string) $courier->type,
            'url' => $courier->url,
            'status' => (int) ($courier->status ?? 0),
            'api_key' => '',
            'secret_key' => '',
            'client_id' => $courier->client_id ?: '',
            'client_secret' => '',
            'username' => $courier->username ?: '',
            'password' => '',
            'token' => $courier->token ?: '',
            'token_expires_at' => optional($courier->token_expires_at)->toDateTimeString(),
            'has_api_key' => trim((string) ($courier->api_key ?? '')) !== '',
            'has_secret_key' => trim((string) ($courier->secret_key ?? '')) !== '',
            'has_client_secret' => trim((string) ($courier->client_secret ?? '')) !== '',
            'has_password' => trim((string) ($courier->password ?? '')) !== '',
            'updated_at' => optional($courier->updated_at)->toDateTimeString(),
        ];
    }
}
