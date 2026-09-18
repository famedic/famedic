<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseShare;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreateLaboratoryPurchaseShare
{
    public function ttlHours(): int
    {
        return max(1, (int) config('famedic.laboratory_purchase_share.ttl_hours', 72));
    }

    public function expiresAt(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addHours($this->ttlHours());
    }

    /**
     * Ensures a reusable share exists for the purchase and renews its expiration window.
     *
     * @return array{share: LaboratoryPurchaseShare, plain_token: string, url: string, created: bool}
     */
    public function __invoke(LaboratoryPurchase $purchase, User $creator): array
    {
        if ($purchase->trashed()) {
            throw new RuntimeException('No se puede compartir una orden cancelada.');
        }

        $plainToken = null;
        $created = false;

        $share = DB::transaction(function () use ($purchase, $creator, &$plainToken, &$created) {
            $lockedPurchase = LaboratoryPurchase::query()
                ->whereKey($purchase->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPurchase->trashed()) {
                throw new RuntimeException('No se puede compartir una orden cancelada.');
            }

            $expiresAt = $this->expiresAt();

            $reusableShare = LaboratoryPurchaseShare::query()
                ->where('laboratory_purchase_id', $lockedPurchase->id)
                ->whereNull('revoked_at')
                ->whereNotNull('token_encrypted')
                ->latest('id')
                ->first();

            if ($reusableShare) {
                $plainToken = $reusableShare->token_encrypted;
                $reusableShare->forceFill(['expires_at' => $expiresAt])->save();

                return $reusableShare;
            }

            LaboratoryPurchaseShare::query()
                ->where('laboratory_purchase_id', $lockedPurchase->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plainToken = bin2hex(random_bytes(32));
            $created = true;

            return LaboratoryPurchaseShare::query()->create([
                'laboratory_purchase_id' => $lockedPurchase->id,
                'created_by' => $creator->id,
                'token_hash' => hash('sha256', $plainToken),
                'token_encrypted' => $plainToken,
                'expires_at' => $expiresAt,
            ]);
        });

        Log::info($created ? 'laboratory_purchase_share_created' : 'laboratory_purchase_share_renewed', [
            'share_id' => $share->id,
            'laboratory_purchase_id' => $purchase->id,
            'actor_user_id' => $creator->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
        ]);

        return [
            'share' => $share,
            'plain_token' => $plainToken,
            'url' => route('shared.laboratory-orders.show', ['token' => $plainToken]),
            'created' => $created,
        ];
    }
}
