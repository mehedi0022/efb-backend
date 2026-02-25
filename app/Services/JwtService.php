<?php

namespace App\Services;

use App\Exceptions\JwtException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JwtService
{
    private ?string $resolvedSigningKey = null;

    public function issueTokenPair(string $subjectType, int|string $subjectId, array $extraClaims = []): array
    {
        $issuedAt = Carbon::now()->timestamp;
        $accessTtlSeconds = max(60, config('jwt.access_ttl', 15) * 60);
        $refreshTtlSeconds = max($accessTtlSeconds, config('jwt.refresh_ttl', 10080) * 60);

        $baseClaims = array_merge([
            'iss' => (string) config('jwt.issuer'),
            'sub' => (string) $subjectId,
            'subject_type' => $subjectType,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
        ], $extraClaims);

        $accessPayload = array_merge($baseClaims, [
            'jti' => (string) Str::uuid(),
            'token_type' => 'access',
            'exp' => $issuedAt + $accessTtlSeconds,
        ]);

        $refreshPayload = array_merge($baseClaims, [
            'jti' => (string) Str::uuid(),
            'token_type' => 'refresh',
            'exp' => $issuedAt + $refreshTtlSeconds,
        ]);

        return [
            'token_type' => 'Bearer',
            'access_token' => $this->encode($accessPayload),
            'refresh_token' => $this->encode($refreshPayload),
            'expires_in' => $accessTtlSeconds,
            'refresh_expires_in' => $refreshTtlSeconds,
        ];
    }

    public function parseAndValidate(
        string $token,
        ?string $expectedTokenType = 'access',
        ?string $expectedSubjectType = null
    ): array {
        if (trim($token) === '') {
            throw new JwtException('Token is required.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Malformed token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodeSegment($encodedHeader);
        $payload = $this->decodeSegment($encodedPayload);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new JwtException('Unsupported token algorithm.');
        }

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $this->signingKey(), true)
        );

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new JwtException('Token signature mismatch.');
        }

        foreach (['sub', 'subject_type', 'token_type', 'jti', 'exp'] as $requiredClaim) {
            if (!array_key_exists($requiredClaim, $payload)) {
                throw new JwtException("Missing token claim: {$requiredClaim}.");
            }
        }

        $now = Carbon::now()->timestamp;
        $clockSkew = max(0, (int) config('jwt.clock_skew', 60));

        if (isset($payload['nbf']) && ($now + $clockSkew) < (int) $payload['nbf']) {
            throw new JwtException('Token not active yet.');
        }

        if (($now - $clockSkew) >= (int) $payload['exp']) {
            throw new JwtException('Token expired.');
        }

        if ($expectedTokenType && ($payload['token_type'] ?? null) !== $expectedTokenType) {
            throw new JwtException("Invalid token type: expected {$expectedTokenType}.");
        }

        if ($expectedSubjectType && ($payload['subject_type'] ?? null) !== $expectedSubjectType) {
            throw new JwtException('Invalid token subject.');
        }

        if ($this->isRevoked($payload)) {
            throw new JwtException('Token has been revoked.');
        }

        $payload['_token'] = $token;

        return $payload;
    }

    public function revoke(string $token, ?array $payload = null): void
    {
        $payload = $payload ?? $this->parseAndValidate($token, null, null);
        $ttlSeconds = max(60, ((int) $payload['exp']) - Carbon::now()->timestamp);

        Cache::put($this->blacklistKey((string) $payload['jti']), true, $ttlSeconds);
    }

    public function revokeByJti(string $jti, int $expiresAt): void
    {
        $ttlSeconds = max(60, $expiresAt - Carbon::now()->timestamp);
        Cache::put($this->blacklistKey($jti), true, $ttlSeconds);
    }

    public function isRevoked(array $payload): bool
    {
        return Cache::has($this->blacklistKey((string) ($payload['jti'] ?? '')));
    }

    private function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $this->signingKey(), true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    private function decodeSegment(string $segment): array
    {
        $decoded = $this->base64UrlDecode($segment);
        $data = json_decode($decoded, true);

        if (!is_array($data)) {
            throw new JwtException('Invalid token payload.');
        }

        return $data;
    }

    private function signingKey(): string
    {
        if ($this->resolvedSigningKey !== null) {
            return $this->resolvedSigningKey;
        }

        $candidates = [
            (string) config('jwt.secret', ''),
            (string) config('app.key', ''),
            $this->envFileValue('JWT_SECRET'),
            $this->envFileValue('APP_KEY'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '' || strtolower($candidate) === 'null') {
                continue;
            }

            $normalized = $this->normalizeSecret($candidate);
            if ($normalized !== '') {
                $this->resolvedSigningKey = $normalized;

                return $this->resolvedSigningKey;
            }
        }

        throw new JwtException('JWT secret is not configured. Set JWT_SECRET in .env and clear config cache.', 500);
    }

    private function normalizeSecret(string $secret): string
    {
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            return $decoded !== false ? $decoded : '';
        }

        return $secret;
    }

    private function envFileValue(string $key): string
    {
        static $envValues = null;

        if (!is_array($envValues)) {
            $envValues = $this->parseEnvFile(base_path('.env'));
        }

        return (string) ($envValues[$key] ?? '');
    }

    private function parseEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separatorPos));
            if ($name === '') {
                continue;
            }

            $value = trim(substr($line, $separatorPos + 1));

            if ($value !== '') {
                $isDoubleQuoted = str_starts_with($value, '"') && str_ends_with($value, '"');
                $isSingleQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");

                if ($isDoubleQuoted || $isSingleQuoted) {
                    $value = substr($value, 1, -1);
                } else {
                    $commentPos = strpos($value, ' #');
                    if ($commentPos !== false) {
                        $value = rtrim(substr($value, 0, $commentPos));
                    }
                }
            }

            $values[$name] = $value;
        }

        return $values;
    }

    private function blacklistKey(string $jti): string
    {
        return "jwt:blacklist:{$jti}";
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = 4 - (strlen($value) % 4);
        if ($padding < 4) {
            $value .= str_repeat('=', $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JwtException('Token segment decode failed.');
        }

        return $decoded;
    }
}
