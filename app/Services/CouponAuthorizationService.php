<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiarySource;
use App\Models\Coupon;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponAuthorizationService
{
    public function __construct(
        private CouponService $couponService,
        private CouponBeneficiaryService $couponBeneficiaryService,
        private CouponAuthorizationOtpService $authorizationOtpService,
        private CouponAuthorizationProgressNotifier $progressNotifier,
    ) {}

    /**
     * @return array{message: string, is_final: bool, current_approvals: int, required_approvals: int}
     */
    public function approveMaster(Coupon $coupon, User $actor, string $otpVerificationToken): array
    {
        if ($coupon->parent_coupon_id !== null) {
            throw new \DomainException('Solo se aprueban cupones maestros.');
        }

        if ($coupon->approval_status !== CouponApprovalStatus::PendingAuthorization) {
            throw new \DomainException('Este cupón no está pendiente de autorización.');
        }

        if ((int) $coupon->created_by_user_id === (int) $actor->id) {
            throw new \DomainException('No puedes aprobar una solicitud que tú creaste.');
        }

        $administrator = $actor->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            throw new \DomainException('No tienes permiso para aprobar.');
        }

        $this->authorizationOtpService->assertVerified($actor, $otpVerificationToken, (int) $coupon->id, null);

        return DB::transaction(function () use ($coupon, $actor, $otpVerificationToken) {
            $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

            if ($locked->approval_status !== CouponApprovalStatus::PendingAuthorization) {
                throw new \DomainException('Esta solicitud ya fue procesada.');
            }

            $locked->approval_status = CouponApprovalStatus::Active;
            $locked->is_active = true;
            $locked->authorization_code_hash = null;
            $locked->authorization_code_expires_at = null;
            $locked->authorized_at = now();
            $locked->authorized_by_user_id = $actor->id;
            if ((int) $locked->remaining_cents === 0 && (int) $locked->amount_cents > 0) {
                $locked->remaining_cents = (int) $locked->amount_cents;
            }
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            $this->authorizationOtpService->consumeVerificationToken($actor, $otpVerificationToken);

            $this->progressNotifier->notifyMasterApproved($locked->fresh(), $actor, true);

            return [
                'message' => 'Solicitud aprobada. El crédito/cupón ya está activo.',
                'is_final' => true,
                'current_approvals' => 1,
                'required_approvals' => 1,
            ];
        });
    }

    /**
     * @return array{message: string, is_final: bool, current_approvals: int, required_approvals: int}
     */
    public function approveAssignment(Coupon $coupon, CouponApprovalRequest $approvalRequest, User $actor, string $otpVerificationToken): array
    {
        if ((int) $approvalRequest->coupon_id !== (int) $coupon->id) {
            throw new \DomainException('La solicitud no corresponde a este cupón.');
        }

        if ($approvalRequest->type !== 'assignment') {
            throw new \DomainException('Tipo de solicitud no soportado en esta bandeja.');
        }

        if ((int) $approvalRequest->requested_by_user_id === (int) $actor->id) {
            throw new \DomainException('No puedes aprobar una solicitud que tú creaste.');
        }

        $administrator = $actor->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            throw new \DomainException('No tienes permiso para aprobar.');
        }

        $this->authorizationOtpService->assertVerified(
            $actor,
            $otpVerificationToken,
            (int) $coupon->id,
            (int) $approvalRequest->id
        );

        return DB::transaction(function () use ($coupon, $approvalRequest, $actor, $otpVerificationToken) {
            $request = CouponApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->id);

            if ($request->status !== 'pending') {
                throw new \DomainException('La solicitud ya fue procesada.');
            }

            $row = CouponApprovalRequestAuthorizer::query()
                ->where('coupon_approval_request_id', $request->id)
                ->where('administrator_id', $actor->administrator->id)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->status !== 'pending') {
                throw new \DomainException('No tienes una aprobación pendiente en esta solicitud.');
            }

            $row->update([
                'status' => 'approved',
                'user_id' => $actor->id,
                'acted_at' => now(),
            ]);

            $request->current_approvals = CouponApprovalRequestAuthorizer::query()
                ->where('coupon_approval_request_id', $request->id)
                ->where('status', 'approved')
                ->count();

            $required = (int) $request->required_approvals;
            $isFinal = $request->current_approvals >= $required;

            if ($isFinal) {
                $this->executeAssignmentRequest($request, $actor);
                $request->status = 'executed';
                $request->executed_at = now();
            } else {
                $request->status = 'pending';
            }

            $request->save();

            $this->authorizationOtpService->consumeVerificationToken($actor, $otpVerificationToken);

            $request->loadMissing(['coupon', 'requestedByUser']);
            $this->progressNotifier->notifyAssignmentProgress($request, $actor, $isFinal);

            return [
                'message' => $isFinal
                    ? 'Solicitud aprobada y ejecutada.'
                    : 'Aprobación registrada. Faltan '.max(0, $required - (int) $request->current_approvals).' firma(s).',
                'is_final' => $isFinal,
                'current_approvals' => (int) $request->current_approvals,
                'required_approvals' => $required,
            ];
        });
    }

    public function rejectMaster(Coupon $coupon, User $actor, string $reason): array
    {
        if ($coupon->parent_coupon_id !== null) {
            throw new \DomainException('Solo se rechazan cupones maestros.');
        }

        if ($coupon->approval_status !== CouponApprovalStatus::PendingAuthorization) {
            throw new \DomainException('Este cupón no está pendiente de autorización.');
        }

        if ((int) $coupon->created_by_user_id === (int) $actor->id) {
            throw new \DomainException('No puedes rechazar una solicitud que tú creaste.');
        }

        $administrator = $actor->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            throw new \DomainException('No tienes permiso para rechazar.');
        }

        return DB::transaction(function () use ($coupon, $actor, $reason) {
            $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

            if ($locked->approval_status !== CouponApprovalStatus::PendingAuthorization) {
                throw new \DomainException('Esta solicitud ya fue procesada.');
            }

            $locked->approval_status = CouponApprovalStatus::Rejected;
            $locked->is_active = false;
            $locked->authorization_code_hash = null;
            $locked->authorization_code_expires_at = null;
            $locked->rejected_reason = $reason;
            $locked->rejected_by_user_id = $actor->id;
            $locked->rejected_at = now();
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            $this->progressNotifier->notifyMasterRejected($locked->fresh(), $actor, $reason);

            return [
                'message' => 'Solicitud rechazada.',
            ];
        });
    }

    public function rejectAssignment(Coupon $coupon, CouponApprovalRequest $approvalRequest, User $actor, string $reason): array
    {
        if ((int) $approvalRequest->coupon_id !== (int) $coupon->id) {
            throw new \DomainException('La solicitud no corresponde a este cupón.');
        }

        if ((int) $approvalRequest->requested_by_user_id === (int) $actor->id) {
            throw new \DomainException('No puedes rechazar una solicitud que tú creaste.');
        }

        $administrator = $actor->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            throw new \DomainException('No tienes permiso para rechazar.');
        }

        return DB::transaction(function () use ($coupon, $approvalRequest, $actor, $reason) {
            $request = CouponApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->id);

            if ($request->status !== 'pending') {
                throw new \DomainException('La solicitud ya fue procesada.');
            }

            $row = CouponApprovalRequestAuthorizer::query()
                ->where('coupon_approval_request_id', $request->id)
                ->where('administrator_id', $actor->administrator->id)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->status !== 'pending') {
                throw new \DomainException('No tienes una aprobación pendiente en esta solicitud.');
            }

            $row->update([
                'status' => 'rejected',
                'user_id' => $actor->id,
                'acted_at' => now(),
                'comment' => $reason,
            ]);

            $request->status = 'rejected';
            $request->rejected_by_user_id = $actor->id;
            $request->rejected_at = now();
            $request->rejection_reason = $reason;
            $request->save();

            $request->loadMissing(['coupon', 'requestedByUser']);
            $this->progressNotifier->notifyAssignmentRejected($request, $actor, $reason);

            return [
                'message' => 'Solicitud rechazada.',
            ];
        });
    }

    private function executeAssignmentRequest(CouponApprovalRequest $approvalRequest, User $actor): void
    {
        $payload = $approvalRequest->payload ?? [];
        $coupon = Coupon::query()->findOrFail((int) ($payload['coupon_id'] ?? $approvalRequest->coupon_id));
        $sendNotification = (bool) ($payload['send_notification'] ?? true);
        $requestedBy = (int) $approvalRequest->requested_by_user_id;

        if (! empty($payload['pre_approval_only'])
            && empty($payload['emails'])
            && empty($payload['beneficiary_rows'])) {
            return;
        }

        if (($payload['activate_parent_on_execute'] ?? false) === true) {
            $this->activateMasterCoupon($coupon, (int) $actor->id);
            $coupon = $coupon->fresh();
        }

        if (! empty($payload['beneficiary_rows']) && is_array($payload['beneficiary_rows'])) {
            $this->couponBeneficiaryService->confirmRows(
                $coupon,
                $payload['beneficiary_rows'],
                $actor,
                CouponBeneficiarySource::from($payload['source'] ?? 'manual'),
                $sendNotification,
                enforceMaxAssignmentAmount: false
            );
        } elseif (! empty($payload['emails']) && is_array($payload['emails'])) {
            foreach ($payload['emails'] as $email) {
                $user = User::query()->where('email', (string) $email)->first();
                if (! $user) {
                    continue;
                }
                try {
                    $this->couponService->assignUserToCampaignCoupon(
                        $user,
                        $coupon,
                        $sendNotification,
                        $requestedBy,
                        enforceMaxAssignmentAmount: false
                    );
                } catch (\DomainException) {
                    continue;
                }
            }
        } elseif (! empty($payload['email'])) {
            $user = User::query()->where('email', (string) $payload['email'])->firstOrFail();
            $this->couponService->assignUserToCampaignCoupon(
                $user,
                $coupon,
                $sendNotification,
                $requestedBy,
                enforceMaxAssignmentAmount: false
            );
        }
    }

    private function activateMasterCoupon(Coupon $coupon, int $actorUserId): void
    {
        if ($coupon->parent_coupon_id !== null) {
            return;
        }

        $coupon->approval_status = CouponApprovalStatus::Active;
        $coupon->is_active = true;
        $coupon->authorization_code_hash = null;
        $coupon->authorization_code_expires_at = null;
        $coupon->authorized_at = now();
        $coupon->authorized_by_user_id = $actorUserId;
        if ((int) $coupon->remaining_cents === 0 && (int) $coupon->amount_cents > 0) {
            $coupon->remaining_cents = (int) $coupon->amount_cents;
        }
        $coupon->updated_by_user_id = $actorUserId;
        $coupon->save();
    }
}
