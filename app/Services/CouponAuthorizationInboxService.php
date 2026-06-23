<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\PromoCode;
use App\Models\User;

class CouponAuthorizationInboxService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listItemsForAuthorizer(User $user): array
    {
        $administrator = $user->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            return [];
        }

        $items = collect();

        $masters = Coupon::query()
            ->whereNull('parent_coupon_id')
            ->where('approval_status', CouponApprovalStatus::PendingAuthorization)
            ->with([
                'createdByUser:id,name,paternal_lastname,maternal_lastname,email',
                'concept:id,title',
            ])
            ->orderByDesc('id')
            ->get();

        $promoByCouponId = PromoCode::query()
            ->whereIn('coupon_id', $masters->pluck('id'))
            ->get(['id', 'coupon_id', 'code', 'max_redemptions', 'redemptions_count'])
            ->keyBy('coupon_id');

        foreach ($masters as $coupon) {
            $hasAssignmentPending = CouponApprovalRequest::query()
                ->where('coupon_id', $coupon->id)
                ->where('status', 'pending')
                ->where('type', 'assignment')
                ->exists();

            if ($hasAssignmentPending) {
                continue;
            }

            $items->push($this->presentMasterItem($coupon, $promoByCouponId->get($coupon->id), $user, $administrator));
        }

        $assignments = CouponApprovalRequest::query()
            ->where('status', 'pending')
            ->where('type', 'assignment')
            ->whereNotNull('coupon_id')
            ->with([
                'coupon:id,code,description,amount_cents,type,approval_status,is_active,created_by_user_id',
                'coupon.createdByUser:id,name,paternal_lastname,maternal_lastname,email',
                'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
                'authorizers',
            ])
            ->orderByDesc('id')
            ->get();

        $assignmentCouponIds = $assignments->pluck('coupon_id')->filter()->unique();
        $promoForAssignments = PromoCode::query()
            ->whereIn('coupon_id', $assignmentCouponIds)
            ->get(['id', 'coupon_id', 'code'])
            ->keyBy('coupon_id');

        foreach ($assignments as $request) {
            $items->push($this->presentAssignmentItem(
                $request,
                $promoForAssignments->get($request->coupon_id),
                $user,
                $administrator
            ));
        }

        return $items
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? '')
            ->values()
            ->all();
    }

    public function actionableCountFor(User $user): int
    {
        return collect($this->listItemsForAuthorizer($user))
            ->filter(fn (array $row) => (bool) ($row['i_can_act'] ?? false))
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailForAuthorizer(User $user, Coupon $coupon, ?int $approvalRequestId = null): ?array
    {
        $administrator = $user->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            return null;
        }

        if ($coupon->parent_coupon_id !== null) {
            return null;
        }

        $coupon->load([
            'createdByUser:id,name,paternal_lastname,maternal_lastname,email',
            'concept:id,title,description',
        ]);

        $promoCode = PromoCode::query()->where('coupon_id', $coupon->id)->first();

        $assignmentRequest = null;
        if ($approvalRequestId !== null) {
            $assignmentRequest = CouponApprovalRequest::query()
                ->where('id', $approvalRequestId)
                ->where('coupon_id', $coupon->id)
                ->where('type', 'assignment')
                ->first();
        } else {
            $assignmentRequest = CouponApprovalRequest::query()
                ->where('coupon_id', $coupon->id)
                ->where('status', 'pending')
                ->where('type', 'assignment')
                ->orderByDesc('id')
                ->first();
        }

        $isMasterPending = $coupon->approval_status === CouponApprovalStatus::PendingAuthorization;
        $isAssignmentPending = $assignmentRequest && $assignmentRequest->status === 'pending';

        if (! $isMasterPending && ! $isAssignmentPending) {
            return null;
        }

        $kind = $isAssignmentPending ? 'assignment' : 'master_activation';

        return [
            'kind' => $kind,
            'coupon' => $this->presentCouponDetail($coupon),
            'promo_code' => $promoCode ? $this->presentPromoDetail($promoCode) : null,
            'credit_type_label' => $this->creditTypeLabel($coupon, $promoCode),
            'master_activation' => $isMasterPending ? [
                'required_approvals' => 1,
                'current_approvals' => $coupon->approval_status === CouponApprovalStatus::Active ? 1 : 0,
                'remaining_approvals' => $coupon->approval_status === CouponApprovalStatus::PendingAuthorization ? 1 : 0,
            ] : null,
            'assignment_request' => $isAssignmentPending
                ? $this->presentAssignmentDetail($assignmentRequest, $user, $administrator)
                : null,
            'i_can_approve' => $this->canApprove($user, $administrator, $coupon, $assignmentRequest, $kind),
            'i_can_reject' => $this->canReject($user, $administrator, $coupon, $assignmentRequest, $kind),
            'is_creator' => (int) $coupon->created_by_user_id === (int) $user->id
                || ($assignmentRequest && (int) $assignmentRequest->requested_by_user_id === (int) $user->id),
            'show_url' => route('admin.coupons.authorizations.show', ['coupon' => $coupon->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMasterItem(Coupon $coupon, ?PromoCode $promoCode, User $user, Administrator $administrator): array
    {
        $creator = $coupon->createdByUser;

        return [
            'id' => 'master:'.$coupon->id,
            'kind' => 'master_activation',
            'coupon_id' => (int) $coupon->id,
            'approval_request_id' => null,
            'promo_code_id' => $promoCode?->id,
            'credit_type_label' => $this->creditTypeLabel($coupon, $promoCode),
            'title' => $coupon->code ?: ($coupon->concept?->title ?? $coupon->description ?? 'Crédito #'.$coupon->id),
            'description' => $coupon->description,
            'amount_cents' => (int) $coupon->amount_cents,
            'formatted_amount' => formattedCentsPrice((int) $coupon->amount_cents),
            'promo_code' => $promoCode?->code,
            'creator' => $creator ? [
                'name' => $creator->full_name ?: $creator->email,
                'email' => $creator->email,
            ] : null,
            'approval_status' => $coupon->approval_status?->value,
            'required_approvals' => 1,
            'current_approvals' => 0,
            'remaining_approvals' => 1,
            'i_can_act' => $this->canApprove($user, $administrator, $coupon, null, 'master_activation'),
            'created_at' => $coupon->created_at?->toIso8601String(),
            'show_url' => route('admin.coupons.authorizations.show', ['coupon' => $coupon->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignmentItem(
        CouponApprovalRequest $request,
        ?PromoCode $promoCode,
        User $user,
        Administrator $administrator
    ): array {
        $coupon = $request->coupon;
        $creator = $request->requestedByUser;
        $required = (int) $request->required_approvals;
        $current = (int) $request->current_approvals;

        return [
            'id' => 'assignment:'.$request->id,
            'kind' => 'assignment',
            'coupon_id' => (int) $request->coupon_id,
            'approval_request_id' => (int) $request->id,
            'promo_code_id' => $promoCode?->id,
            'credit_type_label' => $coupon ? $this->creditTypeLabel($coupon, $promoCode) : 'Asignación',
            'title' => $coupon?->code ?: ($coupon?->description ?? 'Asignación #'.$request->id),
            'description' => $coupon?->description,
            'amount_cents' => (int) ($coupon?->amount_cents ?? 0),
            'formatted_amount' => formattedCentsPrice((int) ($coupon?->amount_cents ?? 0)),
            'promo_code' => $promoCode?->code,
            'creator' => $creator ? [
                'name' => $creator->full_name ?: $creator->email,
                'email' => $creator->email,
            ] : null,
            'approval_status' => $coupon?->approval_status?->value,
            'required_approvals' => $required,
            'current_approvals' => $current,
            'remaining_approvals' => max(0, $required - $current),
            'pre_approval_only' => (bool) ($request->payload['pre_approval_only'] ?? false),
            'i_can_act' => $this->canApprove($user, $administrator, $coupon, $request, 'assignment'),
            'created_at' => $request->created_at?->toIso8601String(),
            'show_url' => route('admin.coupons.authorizations.show', [
                'coupon' => $request->coupon_id,
                'request' => $request->id,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCouponDetail(Coupon $coupon): array
    {
        $creator = $coupon->createdByUser;

        return [
            'id' => (int) $coupon->id,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'type' => $coupon->type?->value,
            'type_label' => $coupon->type?->label(),
            'amount_cents' => (int) $coupon->amount_cents,
            'formatted_amount' => formattedCentsPrice((int) $coupon->amount_cents),
            'max_beneficiaries' => $coupon->max_beneficiaries,
            'valid_from' => $coupon->valid_from?->toIso8601String(),
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'min_purchase_cents' => $coupon->min_purchase_cents,
            'formatted_min_purchase' => $coupon->formatted_min_purchase,
            'concept' => $coupon->concept_other ?: ($coupon->concept?->title ?? null),
            'approval_status' => $coupon->approval_status?->value,
            'is_active' => (bool) $coupon->is_active,
            'creator' => $creator ? [
                'name' => $creator->full_name ?: $creator->email,
                'email' => $creator->email,
            ] : null,
            'rejected_reason' => $coupon->rejected_reason,
            'rejected_at' => $coupon->rejected_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPromoDetail(PromoCode $promoCode): array
    {
        return [
            'id' => (int) $promoCode->id,
            'code' => $promoCode->code,
            'max_redemptions' => $promoCode->max_redemptions,
            'max_uses_per_user' => (int) $promoCode->max_uses_per_user,
            'redemptions_count' => (int) $promoCode->redemptions_count,
            'is_active' => (bool) $promoCode->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignmentDetail(
        CouponApprovalRequest $request,
        User $user,
        Administrator $administrator
    ): array {
        $request->loadMissing([
            'authorizers.administrator.user:id,name,paternal_lastname,maternal_lastname,email',
            'authorizers.actedByUser:id,name,paternal_lastname,maternal_lastname,email',
            'requestedByUser:id,name,paternal_lastname,maternal_lastname,email',
        ]);

        $payload = is_array($request->payload) ? $request->payload : [];
        $myAdminId = (int) $administrator->id;

        $participants = $request->authorizers->map(function (CouponApprovalRequestAuthorizer $row) use ($myAdminId) {
            $adminUser = $row->administrator?->user;
            $actor = $row->actedByUser;

            return [
                'administrator_id' => (int) $row->administrator_id,
                'label' => $adminUser?->full_name ?: ($adminUser?->email ?? 'Autorizador #'.$row->administrator_id),
                'email' => $adminUser?->email,
                'status' => $row->status,
                'is_me' => (int) $row->administrator_id === $myAdminId,
                'acted_at' => $row->acted_at?->toIso8601String(),
                'comment' => $row->comment,
                'acted_by' => $actor ? [
                    'name' => $actor->full_name,
                    'email' => $actor->email,
                ] : null,
            ];
        })->values()->all();

        $emails = [];
        if (! empty($payload['emails']) && is_array($payload['emails'])) {
            $emails = array_values(array_filter(array_map('strval', $payload['emails'])));
        } elseif (! empty($payload['email'])) {
            $emails = [(string) $payload['email']];
        }

        $required = (int) $request->required_approvals;
        $current = (int) $request->current_approvals;

        return [
            'id' => (int) $request->id,
            'status' => $request->status,
            'required_approvals' => $required,
            'current_approvals' => $current,
            'remaining_approvals' => max(0, $required - $current),
            'pre_approval_only' => (bool) ($payload['pre_approval_only'] ?? false),
            'activate_parent_on_execute' => (bool) ($payload['activate_parent_on_execute'] ?? false),
            'beneficiary_emails' => array_slice($emails, 0, 50),
            'beneficiary_count' => count($emails),
            'requested_by' => $request->requestedByUser ? [
                'name' => $request->requestedByUser->full_name ?: $request->requestedByUser->email,
                'email' => $request->requestedByUser->email,
            ] : null,
            'participants' => $participants,
            'rejection_reason' => $request->rejection_reason,
            'rejected_at' => $request->rejected_at?->toIso8601String(),
        ];
    }

    private function creditTypeLabel(Coupon $coupon, ?PromoCode $promoCode): string
    {
        if ($promoCode !== null) {
            return 'Código promocional';
        }

        return $coupon->type?->label() ?? 'Crédito';
    }

    private function canApprove(
        User $user,
        Administrator $administrator,
        ?Coupon $coupon,
        ?CouponApprovalRequest $assignmentRequest,
        string $kind
    ): bool {
        if (! $administrator->hasRole('autorizador')) {
            return false;
        }

        if ($kind === 'master_activation') {
            if (! $coupon || $coupon->approval_status !== CouponApprovalStatus::PendingAuthorization) {
                return false;
            }

            return (int) $coupon->created_by_user_id !== (int) $user->id;
        }

        if (! $assignmentRequest || $assignmentRequest->status !== 'pending') {
            return false;
        }

        if ((int) $assignmentRequest->requested_by_user_id === (int) $user->id) {
            return false;
        }

        return $assignmentRequest->authorizers->contains(
            fn (CouponApprovalRequestAuthorizer $row) => (int) $row->administrator_id === (int) $administrator->id
                && $row->status === 'pending'
        );
    }

    private function canReject(
        User $user,
        Administrator $administrator,
        ?Coupon $coupon,
        ?CouponApprovalRequest $assignmentRequest,
        string $kind
    ): bool {
        return $this->canApprove($user, $administrator, $coupon, $assignmentRequest, $kind);
    }
}
