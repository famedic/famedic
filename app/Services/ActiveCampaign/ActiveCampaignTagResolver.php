<?php

namespace App\Services\ActiveCampaign;

class ActiveCampaignTagResolver
{
    /**
     * @return array{id: int|null, name: string|null, key: string}
     */
    public function resolve(string $logicalKey): array
    {
        $configKey = str_replace('.', '.', $logicalKey);
        $segments = explode('.', $logicalKey);
        $value = config('services.activecampaign.tags');

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [
                    'id' => null,
                    'name' => null,
                    'key' => $logicalKey,
                ];
            }

            $value = $value[$segment];
        }

        if (is_numeric($value)) {
            return [
                'id' => (int) $value,
                'name' => null,
                'key' => $logicalKey,
            ];
        }

        if (is_string($value) && trim($value) !== '') {
            return [
                'id' => ctype_digit(trim($value)) ? (int) trim($value) : null,
                'name' => trim($value),
                'key' => $logicalKey,
            ];
        }

        return [
            'id' => null,
            'name' => null,
            'key' => $logicalKey,
        ];
    }

    public function resolveId(string $logicalKey, ?int $fallbackId = null): ?int
    {
        $resolved = $this->resolve($logicalKey);

        return $resolved['id'] ?? $fallbackId;
    }
}
