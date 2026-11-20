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

    /** RC2 Batch 2 · A2: null = no laboratory brand granted (fail closed); otherwise a list of LaboratoryBrand values. */
    public static function laboratoryBrands(mixed $values): array
    {
        if ($values === null) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            throw new \UnexpectedValueException('Invalid laboratory brand grants');
        }
        foreach ($values as $value) {
            if (! is_string($value) || \App\Enums\LaboratoryBrand::tryFrom($value) === null) {
                throw new \UnexpectedValueException('Invalid laboratory brand grant');
            }
        }

        return array_values(array_unique($values));
    }
}
