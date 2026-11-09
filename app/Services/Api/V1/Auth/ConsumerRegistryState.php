<?php

namespace App\Services\Api\V1\Auth;

use Illuminate\Database\Eloquent\Model;

final class ConsumerRegistryState
{
    public static function active(Model $record): bool
    {
        return $record->exists && $record->enabled === true && $record->expires_at !== null
            && $record->expires_at->isFuture() && $record->getAttribute('revoked_at') === null;
    }

    public static function capabilities(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \UnexpectedValueException('Invalid registry grants');
        }
        foreach ($values as $value) {
            if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9:_-]{1,128}\z/D', $value)) {
                throw new \UnexpectedValueException('Invalid registry grant');
            }
        }

        return array_values(array_unique($values));
    }
}
