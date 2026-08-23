<?php

namespace App\Support;

class MockEfevooPayGatewayCallRecorder
{
    /** @var array<string, int> */
    public static array $counts = [];

    /** @var list<array<string, mixed>> */
    public static array $payloads = [];

    public static function reset(): void
    {
        self::$counts = [];
        self::$payloads = [];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $operation, array $meta = []): void
    {
        self::$counts[$operation] = (self::$counts[$operation] ?? 0) + 1;
        self::$payloads[] = array_merge(['operation' => $operation], $meta);
    }

    public static function count(string $operation): int
    {
        return (int) (self::$counts[$operation] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function payloadsFor(string $operation): array
    {
        return array_values(array_filter(
            self::$payloads,
            fn (array $row) => ($row['operation'] ?? null) === $operation
        ));
    }
}
