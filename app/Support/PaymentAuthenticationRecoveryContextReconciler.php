<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Support\Facades\DB;

class PaymentAuthenticationRecoveryContextReconciler
{
    /**
     * @return array{matched: int, repaired: int, dry_run: bool, context_ids: list<int>}
     */
    public function repairCardVerifiedDrift(bool $dryRun = true): array
    {
        $query = PaymentAuthenticationRecoveryContext::query()
            ->whereNotNull('card_verified_at')
            ->where('status', PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value);

        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if ($dryRun || $ids === []) {
            return [
                'matched' => count($ids),
                'repaired' => 0,
                'dry_run' => $dryRun,
                'context_ids' => $ids,
            ];
        }

        $repaired = DB::transaction(function () use ($ids): int {
            return PaymentAuthenticationRecoveryContext::query()
                ->whereIn('id', $ids)
                ->whereNotNull('card_verified_at')
                ->where('status', PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value)
                ->update([
                    'status' => PaymentAuthenticationRecoveryContextStatus::CardVerified->value,
                    'updated_at' => now(),
                ]);
        });

        return [
            'matched' => count($ids),
            'repaired' => $repaired,
            'dry_run' => false,
            'context_ids' => $ids,
        ];
    }
}
