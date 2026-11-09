<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

/** Contract proposal only: NOT called by CheckoutController or providers. */
final class ProposedLaboratoryContinuity
{
    public function project(array $legacy): array
    {
        if (! isset($legacy['requirements']['has_items']) || ! is_bool($legacy['requirements']['has_items'])) {
            throw new \InvalidArgumentException('Authoritative has_items required');
        }

        return array_replace($legacy, ['assistant_can_pay' => false, 'can_continue_on_platform' => $legacy['requirements']['has_items']]);
    }
}
