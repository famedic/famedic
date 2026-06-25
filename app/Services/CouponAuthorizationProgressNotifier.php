<?php

namespace App\Services;

use App\Mail\CouponAuthorizationDecisionMail;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponApprovalRequest;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CouponAuthorizationProgressNotifier
{
    public function notifyMasterApproved(Coupon $coupon, User $approver, bool $isFinal): void
    {
        $this->sendOnce('master-approved:'.$coupon->id.':final', function () use ($coupon, $approver, $isFinal) {
            $summary = $this->buildCouponSummary($coupon);
            $detailUrl = route('admin.coupons.show', $coupon);

            $this->notifyAuthorizers($coupon, $approver, [
                'event' => 'master_approved',
                'is_final' => $isFinal,
                'summary' => $summary,
                'detail_url' => $detailUrl,
                'current_approvals' => 1,
                'required_approvals' => 1,
                'remaining_approvals' => 0,
            ]);

            $this->notifyCreator($coupon->createdByUser, $coupon, $approver, [
                'event' => 'master_approved_final',
                'is_final' => true,
                'summary' => $summary,
                'detail_url' => $detailUrl,
            ]);
        });
    }

    public function notifyMasterRejected(Coupon $coupon, User $rejector, string $reason): void
    {
        $this->sendOnce('master-rejected:'.$coupon->id, function () use ($coupon, $rejector, $reason) {
            $summary = $this->buildCouponSummary($coupon);
            $detailUrl = route('admin.coupons.show', $coupon);

            $this->notifyAuthorizers($coupon, $rejector, [
                'event' => 'master_rejected',
                'is_final' => true,
                'summary' => $summary,
                'detail_url' => $detailUrl,
                'rejection_reason' => $reason,
            ]);

            $this->notifyCreator($coupon->createdByUser, $coupon, $rejector, [
                'event' => 'master_rejected',
                'is_final' => true,
                'summary' => $summary,
                'detail_url' => $detailUrl,
                'rejection_reason' => $reason,
            ]);
        });
    }

    public function notifyAssignmentProgress(CouponApprovalRequest $request, User $approver, bool $isFinal): void
    {
        $dedupeKey = 'assignment-progress:'.$request->id.':'.(int) $request->current_approvals.':'.($isFinal ? 'final' : 'partial');

        $this->sendOnce($dedupeKey, function () use ($request, $approver, $isFinal) {
            $request->loadMissing(['coupon.createdByUser', 'requestedByUser']);
            $coupon = $request->coupon;
            if (! $coupon) {
                return;
            }

            $summary = $this->buildCouponSummary($coupon);
            $detailUrl = route('admin.coupons.authorizations.show', ['coupon' => $coupon->id, 'request' => $request->id]);
            $required = (int) $request->required_approvals;
            $current = (int) $request->current_approvals;

            $payload = [
                'event' => $isFinal ? 'assignment_approved_final' : 'assignment_approved_partial',
                'is_final' => $isFinal,
                'summary' => $summary,
                'detail_url' => $detailUrl,
                'current_approvals' => $current,
                'required_approvals' => $required,
                'remaining_approvals' => max(0, $required - $current),
            ];

            $this->notifyAuthorizers($coupon, $approver, $payload, excludeUserId: (int) $approver->id);
            $this->notifyCreator($request->requestedByUser, $coupon, $approver, $payload);
        });
    }

    public function notifyAssignmentRejected(CouponApprovalRequest $request, User $rejector, string $reason): void
    {
        $this->sendOnce('assignment-rejected:'.$request->id, function () use ($request, $rejector, $reason) {
            $request->loadMissing(['coupon', 'requestedByUser']);
            $coupon = $request->coupon;
            if (! $coupon) {
                return;
            }

            $summary = $this->buildCouponSummary($coupon);
            $detailUrl = route('admin.coupons.authorizations.show', ['coupon' => $coupon->id, 'request' => $request->id]);

            $payload = [
                'event' => 'assignment_rejected',
                'is_final' => true,
                'summary' => $summary,
                'detail_url' => $detailUrl,
                'rejection_reason' => $reason,
            ];

            $this->notifyAuthorizers($coupon, $rejector, $payload, excludeUserId: (int) $rejector->id);
            $this->notifyCreator($request->requestedByUser, $coupon, $rejector, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyAuthorizers(Coupon $coupon, User $actor, array $payload, ?int $excludeUserId = null): void
    {
        $authorizers = Administrator::role('autorizador')
            ->with('user:id,name,paternal_lastname,maternal_lastname,email')
            ->get();

        foreach ($authorizers as $administrator) {
            $user = $administrator->user;
            if (! $user || ! $user->email) {
                continue;
            }

            if ($excludeUserId !== null && (int) $user->id === $excludeUserId) {
                continue;
            }

            $this->sendMail($user->email, $coupon, $actor, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyCreator(?User $creator, Coupon $coupon, User $actor, array $payload): void
    {
        if (! $creator || ! $creator->email) {
            return;
        }

        if ((int) $creator->id === (int) $actor->id) {
            return;
        }

        $this->sendMail($creator->email, $coupon, $actor, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendMail(string $email, Coupon $coupon, User $actor, array $payload): void
    {
        try {
            Mail::to($email)->send(new CouponAuthorizationDecisionMail($coupon, $actor, $payload));
        } catch (\Throwable $e) {
            Log::warning('coupon_authorization_decision_mail_failed', [
                'coupon_id' => $coupon->id,
                'to' => $email,
                'event' => $payload['event'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendOnce(string $key, callable $callback): void
    {
        if (! Cache::add('coupon-auth-notif:'.$key, 1, now()->addMinutes(2))) {
            return;
        }

        $callback();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCouponSummary(Coupon $coupon): array
    {
        $coupon->loadMissing(['concept']);
        $promoCode = PromoCode::query()->where('coupon_id', $coupon->id)->first();

        $typeLabel = $promoCode ? 'Código promocional' : ($coupon->type?->label() ?? 'Crédito');

        return [
            'code' => $coupon->code ?: ($promoCode?->code ?? '—'),
            'type_label' => $typeLabel,
            'amount' => formattedCentsPrice((int) $coupon->amount_cents),
            'description' => $coupon->description ?: '—',
            'promo_code' => $promoCode?->code,
        ];
    }
}
