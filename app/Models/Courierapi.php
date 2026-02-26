<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class Courierapi extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => 'integer',
        'token_expires_at' => 'datetime',
    ];

    protected function apiKey(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function secretKey(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function clientId(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function clientSecret(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function username(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function password(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function token(): Attribute
    {
        return $this->encryptedAttribute();
    }

    public function tokenExpired(int $bufferSeconds = 90): bool
    {
        /** @var Carbon|null $expiresAt */
        $expiresAt = $this->token_expires_at;

        if (!$expiresAt) {
            return true;
        }

        return $expiresAt->lessThanOrEqualTo(now()->addSeconds(max(0, $bufferSeconds)));
    }

    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->decryptSensitiveValue($value),
            set: fn ($value) => $this->encryptSensitiveValue($value)
        );
    }

    private function encryptSensitiveValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            Crypt::decryptString($text);
            return $text;
        } catch (Throwable $exception) {
            return Crypt::encryptString($text);
        }
    }

    private function decryptSensitiveValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Crypt::decryptString($text);
        } catch (Throwable $exception) {
            return $text;
        }
    }
}
