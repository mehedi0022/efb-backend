<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courierapi;
use App\Models\GeneralSetting;
use App\Models\PaymentGateway;
use App\Models\SmsGateway;
use App\Services\SteadfastCourierService;
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

    public function __construct(protected SteadfastCourierService $steadfastCourierService)
    {
    }

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

    public function steadfastIndex()
    {
        $steadfast = $this->steadfastCourierService->getConfiguration();

        return response()->json([
            'success' => true,
            'data' => $this->steadfastCourierService->formatConfiguration($steadfast),
        ]);
    }

    public function steadfastUpdate(Request $request)
    {
        $validated = $request->validate([
            'status' => 'required|boolean',
            'url' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:255',
            'secret_key' => 'nullable|string|max:255',
        ]);

        $steadfast = $this->steadfastCourierService->updateConfiguration($validated);

        return response()->json([
            'success' => true,
            'message' => 'Steadfast configuration saved successfully.',
            'data' => $this->steadfastCourierService->formatConfiguration($steadfast),
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
            'url' => 'nullable|string|max:255',
        ]);

        try {
            /** @var Courierapi|null $pathao */
            $pathao = Courierapi::query()->where('type', 'pathao')->first();
            $credentials = $this->resolvePathaoCredentials($pathao);

            $tokenResponse = $this->requestPathaoToken(
                clientId: $credentials['client_id'],
                clientSecret: $credentials['client_secret'],
                username: $credentials['username'],
                password: $credentials['password'],
                baseUrl: isset($validated['url'])
                    ? trim((string) $validated['url'])
                    : trim((string) ($pathao?->url ?: $this->pathaoDefaultBaseUrl()))
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
                'url' => $this->pathaoDefaultBaseUrl(),
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

            $credentials = $this->resolvePathaoCredentials($courier);

            $token = trim((string) ($courier->token ?? ''));
            $forceRefresh = $request->boolean('refresh_token') || $token === '' || $courier->tokenExpired();
            if (!$forceRefresh) {
                return;
            }

            $tokenResponse = $this->requestPathaoToken(
                clientId: $credentials['client_id'],
                clientSecret: $credentials['client_secret'],
                username: $credentials['username'],
                password: $credentials['password'],
                baseUrl: trim((string) ($courier->url ?: $this->pathaoDefaultBaseUrl()))
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
        if ($this->pathaoCredentialsAreComplete($this->pathaoCredentialsFromEnvironment())) {
            return true;
        }

        return $this->pathaoCredentialsAreComplete($this->pathaoCredentialsFromCourier($courier));
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
            'grant_type' => trim((string) config('services.pathao.grant_type', 'password')) ?: 'password',
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
        $token = trim((string) (
            $data['access_token']
            ?? data_get($data, 'data.access_token')
            ?? data_get($data, 'result.access_token')
            ?? ''
        ));
        if ($token === '') {
            throw ValidationException::withMessages([
                'token' => ['Pathao did not return a valid access token.'],
            ]);
        }

        $expiresInRaw = $data['expires_in']
            ?? data_get($data, 'data.expires_in')
            ?? data_get($data, 'result.expires_in');
        $expiresIn = $expiresInRaw !== null && is_numeric($expiresInRaw)
            ? max(60, (int) $expiresInRaw)
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
        $candidate = trim((string) ($baseUrl ?? $this->pathaoDefaultBaseUrl()));
        if ($candidate === '') {
            $candidate = $this->pathaoDefaultBaseUrl();
        }

        if (preg_match('/\/issue-token\/?$/i', $candidate)) {
            return rtrim($candidate, '/');
        }

        $normalizedBase = rtrim($candidate, '/');
        return $normalizedBase . self::PATHAO_ISSUE_TOKEN_PATH;
    }

    private function resolvePathaoErrorMessage(Response $response): string
    {
        $message = trim((string) (
            $response->json('message')
            ?? $response->json('data.message')
            ?? $response->json('result.message')
            ?? ''
        ));

        $errors = $this->extractPathaoApiErrors($response);
        if (!empty($errors)) {
            $combined = implode(' ', $errors);
            if ($message === '' || $this->isGenericPathaoErrorMessage($message)) {
                return mb_substr($combined, 0, 350);
            }

            return mb_substr(trim($message . ' ' . $combined), 0, 350);
        }

        if ($message !== '' && !$this->isGenericPathaoErrorMessage($message)) {
            return mb_substr($message, 0, 350);
        }

        $rawBody = trim((string) $response->body());
        if ($rawBody !== '') {
            if ($message !== '' && !$this->isGenericPathaoErrorMessage($message)) {
                return mb_substr($message, 0, 350);
            }

            return mb_substr($rawBody, 0, 350);
        }

        if ($message !== '') {
            return mb_substr($message, 0, 350);
        }

        return 'Pathao token request failed.';
    }

    /**
     * @return array<int, string>
     */
    private function extractPathaoApiErrors(Response $response): array
    {
        $segments = [
            $response->json('errors'),
            $response->json('error'),
            $response->json('data.errors'),
            $response->json('data.error'),
            $response->json('data.validation_errors'),
            $response->json('result.error'),
            $response->json('result.errors'),
            $response->json('data.message'),
            $response->json('result.message'),
        ];

        return collect($segments)
            ->flatMap(fn ($segment) => $this->flattenPathaoErrorSegment($segment))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '' && !$this->isGenericPathaoErrorMessage($item))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function flattenPathaoErrorSegment(mixed $segment, ?string $field = null): array
    {
        if ($segment === null || is_bool($segment)) {
            return [];
        }

        if (is_numeric($segment)) {
            return [];
        }

        if (is_string($segment)) {
            $message = trim($segment);
            if ($message === '' || $this->shouldIgnorePathaoErrorField($field)) {
                return [];
            }

            if ($field !== null && trim($field) !== '' && !$this->isPathaoErrorContainerKey($field)) {
                return [
                    sprintf('%s: %s', $this->formatPathaoErrorField($field), $message),
                ];
            }

            return [$message];
        }

        if (!is_array($segment) || empty($segment)) {
            return [];
        }

        if (array_key_exists('field', $segment) && array_key_exists('message', $segment)) {
            $fieldName = trim((string) ($segment['field'] ?? ''));
            $fieldMessage = trim((string) ($segment['message'] ?? ''));

            if ($fieldMessage !== '') {
                if ($fieldName === '') {
                    return [$fieldMessage];
                }

                return [
                    sprintf('%s: %s', $this->formatPathaoErrorField($fieldName), $fieldMessage),
                ];
            }
        }

        $messages = [];
        foreach ($segment as $key => $value) {
            $nextField = $field;
            if (is_string($key) && trim($key) !== '' && !$this->isPathaoErrorContainerKey($key)) {
                $nextField = $key;
            }

            $messages = array_merge(
                $messages,
                $this->flattenPathaoErrorSegment($value, $nextField)
            );
        }

        return $messages;
    }

    private function isPathaoErrorContainerKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), [
            'errors',
            'error',
            'messages',
            'message',
            'data',
            'result',
            'details',
            'detail',
            'validation_errors',
        ], true);
    }

    private function shouldIgnorePathaoErrorField(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        return in_array(strtolower(trim($field)), [
            'success',
            'status',
            'status_code',
            'http_code',
            'code',
            'error_code',
            'type',
        ], true);
    }

    private function formatPathaoErrorField(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', trim($field)));
    }

    private function isGenericPathaoErrorMessage(string $message): bool
    {
        return in_array(strtolower(trim($message)), [
            'please fix the given errors',
            'please fix the given error',
            'validation failed',
            'unprocessable entity',
            'the given data was invalid.',
            'error',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCourierConfig(Courierapi $courier): array
    {
        $isPathao = strtolower(trim((string) $courier->type)) === 'pathao';
        $envCredentials = $this->pathaoCredentialsFromEnvironment();
        $storedCredentials = $this->pathaoCredentialsFromCourier($courier);
        $hasAnyStoredCredential = collect($storedCredentials)
            ->contains(fn ($value) => trim((string) $value) !== '');
        $managedByBackend = $isPathao
            && $this->pathaoCredentialsAreComplete($envCredentials)
            && !$hasAnyStoredCredential;

        return [
            'id' => (int) $courier->id,
            'type' => (string) $courier->type,
            'url' => $courier->url,
            'status' => (int) ($courier->status ?? 0),
            'api_key' => '',
            'secret_key' => '',
            'client_id' => '',
            'client_secret' => '',
            'username' => '',
            'password' => '',
            'token' => '',
            'token_expires_at' => optional($courier->token_expires_at)->toDateTimeString(),
            'has_api_key' => trim((string) ($courier->api_key ?? '')) !== '',
            'has_secret_key' => trim((string) ($courier->secret_key ?? '')) !== '',
            'has_client_id' => trim((string) ($storedCredentials['client_id'] ?? '')) !== '' || $managedByBackend,
            'has_username' => trim((string) ($storedCredentials['username'] ?? '')) !== '' || $managedByBackend,
            'has_client_secret' => trim((string) ($storedCredentials['client_secret'] ?? '')) !== '' || $managedByBackend,
            'has_password' => trim((string) ($storedCredentials['password'] ?? '')) !== '' || $managedByBackend,
            'has_token' => trim((string) ($courier->token ?? '')) !== '',
            'managed_by_backend' => $managedByBackend,
            'updated_at' => optional($courier->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function pathaoCredentialsFromEnvironment(): array
    {
        return [
            'client_id' => trim((string) config('services.pathao.client_id', '')),
            'client_secret' => trim((string) config('services.pathao.client_secret', '')),
            'username' => trim((string) config('services.pathao.username', '')),
            'password' => trim((string) config('services.pathao.password', '')),
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function pathaoCredentialsFromCourier(Courierapi $courier): array
    {
        return [
            'client_id' => trim((string) ($courier->client_id ?? '')),
            'client_secret' => trim((string) ($courier->client_secret ?? '')),
            'username' => trim((string) ($courier->username ?? '')),
            'password' => trim((string) ($courier->password ?? '')),
        ];
    }

    /**
     * @param array{client_id:string,client_secret:string,username:string,password:string} $credentials
     */
    private function pathaoCredentialsAreComplete(array $credentials): bool
    {
        return $credentials['client_id'] !== ''
            && $credentials['client_secret'] !== ''
            && $credentials['username'] !== ''
            && $credentials['password'] !== '';
    }

    /**
     * @return array{client_id:string,client_secret:string,username:string,password:string}
     */
    private function resolvePathaoCredentials(?Courierapi $courier = null): array
    {
        if ($courier !== null) {
            $storedCredentials = $this->pathaoCredentialsFromCourier($courier);
            if ($this->pathaoCredentialsAreComplete($storedCredentials)) {
                return $storedCredentials;
            }
        }

        $secureCredentials = $this->pathaoCredentialsFromEnvironment();
        if ($this->pathaoCredentialsAreComplete($secureCredentials)) {
            return $secureCredentials;
        }

        throw ValidationException::withMessages([
            'credentials' => ['Pathao credentials are missing in backend configuration.'],
        ]);
    }

    private function pathaoDefaultBaseUrl(): string
    {
        $configured = trim((string) config('services.pathao.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return self::PATHAO_DEFAULT_BASE_URL;
    }
}
