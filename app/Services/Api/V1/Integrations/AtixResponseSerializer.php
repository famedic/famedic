<?php

namespace App\Services\Api\V1\Integrations;

use InvalidArgumentException;

final class AtixResponseSerializer
{
    public function encode(array $response): string
    {
        return json_encode(
            $this->canonicalize($response),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    public function decode(mixed $serialized): array
    {
        if (is_array($serialized)) {
            return $serialized;
        }

        if (! is_string($serialized)) {
            throw new InvalidArgumentException('ATIX response body must be serialized JSON.');
        }

        $decoded = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('ATIX response body must decode to an array.');
        }

        return $decoded;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
