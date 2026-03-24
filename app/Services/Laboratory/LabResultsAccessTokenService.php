<?php

namespace App\Services\Laboratory;

use App\Models\LabResultAccessToken;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Str;

class LabResultsAccessTokenService
{
    public function generate(User $user, LaboratoryPurchase $purchase): string
    {
        $plainToken = Str::random(64);

        LabResultAccessToken::query()->create([
            'user_id' => $user->id,
            'laboratory_purchase_id' => $purchase->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
        ]);

        return $plainToken;
    }
}
