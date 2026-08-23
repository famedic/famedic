<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;

class LaravelDatabaseSessionPayloadCodec
{
    public function isEncrypted(): bool
    {
        return (bool) config('session.encrypt', false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $storedPayload): ?array
    {
        $binary = base64_decode($storedPayload, true);

        if ($binary === false) {
            return null;
        }

        if ($this->isEncrypted()) {
            try {
                $binary = (string) app('encrypter')->decrypt($binary);
            } catch (DecryptException) {
                return null;
            }
        }

        $data = @unserialize($binary, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function encode(array $attributes): string
    {
        $serialized = serialize($attributes);

        if ($this->isEncrypted()) {
            $serialized = app('encrypter')->encrypt($serialized);
        }

        return base64_encode($serialized);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hasSensitiveKeys(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if ($this->isSensitiveKey($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function sensitiveKeys(array $payload): array
    {
        $keys = [];

        foreach (array_keys($payload) as $key) {
            if ($this->isSensitiveKey($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function isSensitiveKey(string $key): bool
    {
        return $key === PaymentAuthenticationSensitiveCardDataStore::LEGACY_SESSION_KEY
            || str_starts_with($key, '3ds_card_data_');
    }
}
