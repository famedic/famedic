<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\MedicalCheckout\MedicalSettlementOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Not automatically dispatched or scheduled; authorization is rechecked at execution. */
final class ProcessMedicalSettlementOutbox implements ShouldQueue
{
    use Dispatchable,Queueable;

    public int $tries = 1;

    public function __construct(public int $operatorId, public int $outboxId) {}

    public function handle(MedicalSettlementOutbox $outbox): void
    {
        $outbox->deliver(User::findOrFail($this->operatorId), $this->outboxId);
    }
}
