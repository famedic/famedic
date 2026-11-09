<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** No production adapter is installed. Implementations MUST provide serializable
 * transactions over cart/source/confirmation/receipt, uniqueness and rollback.
 * Recoverable opaque aliases and receipts require approved authenticated encryption.
 * All legacy cart/source writers must share this protocol before activation.
 */
interface BenefitStateStore
{
    public function transaction(callable $operation): mixed;

    public function get(string $table, string $key): ?array;

    public function put(string $table, string $key, array $value): void;

    public function all(string $table): array;
}
