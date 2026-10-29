<?php

namespace App\Support\Api\V1;

use App\Models\User;

final class AtixPhoneLookupRecognitionPayload
{
    public const STATUS_RECOGNIZED = 'recognized_unverified';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @return array<string, mixed>
     */
    public static function recognized(User $user, bool $personalizationAllowed): array
    {
        $personalization = ['allowed' => $personalizationAllowed];
        if ($personalizationAllowed) {
            $firstName = self::firstName($user);
            if ($firstName !== null && $firstName !== '') {
                $personalization['first_name'] = $firstName;
            }
        }

        return [
            'recognition_status' => self::STATUS_RECOGNIZED,
            'assurance_level' => self::STATUS_RECOGNIZED,
            'personalization' => $personalization,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function unknown(): array
    {
        return [
            'recognition_status' => self::STATUS_UNKNOWN,
            'assurance_level' => 'none',
            'personalization' => ['allowed' => false],
        ];
    }

    private static function firstName(User $user): ?string
    {
        $name = trim((string) $user->name);
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) && isset($parts[0]) ? $parts[0] : null;
    }
}
