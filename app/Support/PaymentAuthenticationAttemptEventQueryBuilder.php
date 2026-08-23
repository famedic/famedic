<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class PaymentAuthenticationAttemptEventQueryBuilder extends Builder
{
    public function update(array $values)
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }

    public function delete()
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }

    public function forceDelete()
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }
}
