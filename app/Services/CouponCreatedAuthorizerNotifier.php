<?php

namespace App\Services;

use App\Mail\CouponCreatedAuthorizerMail;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CouponCreatedAuthorizerNotifier
{
    public function notify(Coupon $coupon, User $creator, ?PromoCode $promoCode = null): void
    {
        $coupon->loadMissing(['concept', 'createdByUser.administrator.roles']);
        if ($promoCode !== null) {
            $promoCode->loadMissing('coupon');
        }

        $summary = $this->buildSummary($coupon, $creator, $promoCode);
        $detailUrl = $promoCode !== null
            ? route('admin.coupons.promo-codes.show', $promoCode)
            : route('admin.coupons.authorizations.show', $coupon);

        $authorizers = Administrator::role('autorizador')
            ->with('user:id,name,paternal_lastname,maternal_lastname,email')
            ->get();

        $sent = 0;
        foreach ($authorizers as $administrator) {
            $email = $administrator->user?->email;
            if ($email === null || trim($email) === '') {
                continue;
            }

            try {
                Mail::to($email)->send(new CouponCreatedAuthorizerMail(
                    coupon: $coupon,
                    creator: $creator,
                    summary: $summary,
                    detailUrl: $detailUrl,
                ));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('coupon_created_authorizer_mail_failed', [
                    'coupon_id' => $coupon->id,
                    'authorizer_administrator_id' => $administrator->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('coupon_created_authorizer_notified', [
            'coupon_id' => $coupon->id,
            'creator_user_id' => $creator->id,
            'authorizers_notified' => $sent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(Coupon $coupon, User $creator, ?PromoCode $promoCode = null): array
    {
        $concept = $coupon->concept_other
            ?: ($coupon->concept?->title ?? '—');

        $validityParts = [];
        if ($coupon->valid_from !== null) {
            $validityParts[] = 'desde '.$coupon->valid_from->timezone(config('app.timezone'))->format('d/m/Y H:i');
        }
        if ($coupon->expires_at !== null) {
            $validityParts[] = 'hasta '.$coupon->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i');
        }
        $validity = $validityParts !== [] ? implode(' ', $validityParts) : 'Sin vigencia definida';

        $approvalStatus = $coupon->approval_status;
        $approvalLabel = match (true) {
            $approvalStatus?->value === 'pending_authorization' => 'Pendiente de autorización',
            $approvalStatus?->value === 'active' => 'Activo',
            $approvalStatus?->value === 'rejected' => 'Rechazado',
            default => (string) ($approvalStatus?->value ?? '—'),
        };

        $creatorRole = $creator->administrator?->roles->pluck('name')->join(', ') ?: '—';

        $isPromoCode = $promoCode !== null;
        $promoCodeValue = $isPromoCode ? ($promoCode->code ?: '—') : null;
        $couponCode = $coupon->code ?: '—';

        return [
            'is_promo_code' => $isPromoCode,
            'code' => $promoCodeValue ?? $couponCode,
            'promo_code' => $promoCodeValue,
            'type_label' => $isPromoCode ? 'Código promocional compartido' : ($coupon->type?->label() ?? '—'),
            'amount' => formattedCentsPrice((int) $coupon->amount_cents),
            'concept' => $concept,
            'description' => $coupon->description ?: '—',
            'approval_status' => $approvalLabel,
            'is_active' => (bool) $coupon->is_active,
            'validity' => $validity,
            'min_purchase' => $coupon->formatted_min_purchase ?? 'Sin requisito',
            'max_beneficiaries' => $isPromoCode
                ? ($promoCode->max_redemptions !== null ? (string) $promoCode->max_redemptions : 'Sin límite')
                : ($coupon->max_beneficiaries !== null ? (string) $coupon->max_beneficiaries : 'Sin límite'),
            'max_uses_per_user' => $isPromoCode ? (string) $promoCode->max_uses_per_user : null,
            'creator_name' => $creator->full_name ?: $creator->name,
            'creator_role' => $creatorRole,
            'created_at' => $coupon->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
        ];
    }
}
