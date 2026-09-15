<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreateLaboratoryPurchaseShare
{
    /**
     * The public link expires 48 hours after the appointment, capped at 30 days
     * after creation so a distant appointment cannot leave a link active too long.
     */
    public function expiresAt(LaboratoryPurchase $purchase, ?\Illuminate\Support\Carbon $createdAt = null): \Illuminate\Support\Carbon
    {
        $createdAt ??= now();
        $maxExpiresAt = $createdAt->copy()->addDays(30);
        $appointmentExpiresAt = $purchase->laboratoryAppointment?->appointment_date
            ? localizedDate($purchase->laboratoryAppointment->appointment_date)->addHours(48)
            : $createdAt->copy()->addHours(48);

        if ($appointmentExpiresAt->lte($createdAt)) {
            $appointmentExpiresAt = $createdAt->copy()->addHours(48);
        }

        return $appointmentExpiresAt->gt($maxExpiresAt) ? $maxExpiresAt : $appointmentExpiresAt;
    }

    /**
     * @return array{share: LaboratoryPurchaseShare, plain_token: string, url: string}
     */
    public function __invoke(LaboratoryPurchase $purchase, User $creator): array
    {
        if ($purchase->trashed()) {
            throw new RuntimeException('No se puede compartir una orden cancelada.');
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = null;

        $share = DB::transaction(function () use ($purchase, $creator, $tokenHash, &$expiresAt) {
            // The purchase row is the mutex: concurrent regenerations for the same order serialize here.
            $lockedPurchase = LaboratoryPurchase::query()
                ->whereKey($purchase->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPurchase->trashed()) {
                throw new RuntimeException('No se puede compartir una orden cancelada.');
            }

            $lockedPurchase->loadMissing('laboratoryAppointment');
            $createdAt = now();
            $expiresAt = $this->expiresAt($lockedPurchase, $createdAt);

            LaboratoryPurchaseShare::query()
                ->where('laboratory_purchase_id', $lockedPurchase->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return LaboratoryPurchaseShare::query()->create([
                'laboratory_purchase_id' => $lockedPurchase->id,
                'created_by' => $creator->id,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);
        });

        Log::info('laboratory_purchase_share_created', [
            'share_id' => $share->id,
            'laboratory_purchase_id' => $purchase->id,
            'actor_user_id' => $creator->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return [
            'share' => $share,
            'plain_token' => $plainToken,
            'url' => route('shared.laboratory-orders.show', ['token' => $plainToken]),
        ];
    }
}
