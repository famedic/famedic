<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseShare;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RevokeLaboratoryPurchaseShare
{
    public function __invoke(LaboratoryPurchase $purchase, LaboratoryPurchaseShare $share, User $actor): LaboratoryPurchaseShare
    {
        abort_unless((int) $share->laboratory_purchase_id === (int) $purchase->id, 404);

        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        Log::info('laboratory_purchase_share_revoked', [
            'share_id' => $share->id,
            'laboratory_purchase_id' => $purchase->id,
            'actor_user_id' => $actor->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
        ]);

        return $share;
    }
}
