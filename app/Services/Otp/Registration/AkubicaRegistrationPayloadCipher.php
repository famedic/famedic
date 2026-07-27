<?php

namespace App\Services\Otp\Registration;

use App\Exceptions\Otp\RegistrationIntentPayloadException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Encrypts/decrypts AkubicaRegistrationPayload via Laravel Crypt (APP_KEY).
 * Controllers must never call this casually — only IntentService.
 */
final class AkubicaRegistrationPayloadCipher
{
    public function encrypt(AkubicaRegistrationPayload $payload): string
    {
        try {
            $json = json_encode(
                $payload->toEncryptableArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new RegistrationIntentPayloadException(
                'No se pudo serializar el payload de registro.',
                'REGISTRATION_INTENT_PAYLOAD_INVALID',
            );
        }

        return Crypt::encryptString($json);
    }

    public function decrypt(string $ciphertext): AkubicaRegistrationPayload
    {
        if ($ciphertext === '') {
            throw new RegistrationIntentPayloadException(
                'El payload del intent no esta disponible.',
                'REGISTRATION_INTENT_PAYLOAD_ABSENT',
            );
        }

        try {
            $json = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            throw new RegistrationIntentPayloadException(
                'El payload del intent no es valido.',
                'REGISTRATION_INTENT_PAYLOAD_CORRUPT',
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RegistrationIntentPayloadException(
                'El payload del intent no es valido.',
                'REGISTRATION_INTENT_PAYLOAD_CORRUPT',
            );
        }

        if (! is_array($decoded)) {
            throw new RegistrationIntentPayloadException(
                'El payload del intent no es valido.',
                'REGISTRATION_INTENT_PAYLOAD_CORRUPT',
            );
        }

        try {
            return AkubicaRegistrationPayload::fromDecryptedArray($decoded);
        } catch (\InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'version')
                ? 'REGISTRATION_INTENT_PAYLOAD_VERSION'
                : 'REGISTRATION_INTENT_PAYLOAD_INVALID';

            throw new RegistrationIntentPayloadException(
                'El payload del intent no es valido.',
                $code,
            );
        }
    }
}
