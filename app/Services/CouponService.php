<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Mail\CouponAssignedMail;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\CouponAuditLog;
use App\Models\CouponBeneficiaryApprovalRule;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\CouponUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class CouponService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function getUserBalance(int $userId): int
    {
        return (int) Coupon::query()
            ->where('coupons.is_active', true)
            ->where('coupons.remaining_cents', '>', 0)
            ->where('coupons.type', CouponType::Balance)
            ->join('coupon_user', 'coupon_user.coupon_id', '=', 'coupons.id')
            ->where('coupon_user.user_id', $userId)
            ->whereNull('coupon_user.used_at')
            ->sum('coupons.remaining_cents');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id:int, remaining_cents:int, code:?string}>
     */
    public function getAvailableCoupons(int $userId): \Illuminate\Support\Collection
    {
        return Coupon::query()
            ->select(['coupons.id', 'coupons.remaining_cents', 'coupons.code'])
            ->where('coupons.is_active', true)
            ->where('coupons.remaining_cents', '>', 0)
            ->where('coupons.type', CouponType::Balance)
            ->where('coupons.approval_status', CouponApprovalStatus::Active)
            ->join('coupon_user', 'coupon_user.coupon_id', '=', 'coupons.id')
            ->where('coupon_user.user_id', $userId)
            ->whereNull('coupon_user.used_at')
            ->orderBy('coupons.id')
            ->get();
    }

    public function resolveRequiredApprovals(int $amountCents, int $beneficiariesCount): int
    {
        $settings = CouponAdminSettings::singleton();

        $approvalsByAmount = 0;
        if (
            $settings->amount_threshold_cents !== null &&
            $settings->required_approvals_by_amount > 0 &&
            $amountCents >= $settings->amount_threshold_cents
        ) {
            $approvalsByAmount = (int) $settings->required_approvals_by_amount;
        }

        $rule = CouponBeneficiaryApprovalRule::query()
            ->where('min_beneficiaries', '<=', $beneficiariesCount)
            ->where(function ($q) use ($beneficiariesCount) {
                $q->whereNull('max_beneficiaries')
                    ->orWhere('max_beneficiaries', '>=', $beneficiariesCount);
            })
            ->orderByDesc('required_approvals')
            ->first();

        $approvalsByBeneficiaries = (int) ($rule?->required_approvals ?? 0);

        return max($approvalsByAmount, $approvalsByBeneficiaries);
    }

    public function createApprovalRequest(
        string $type,
        int $requestedByUserId,
        int $requiredApprovals,
        array $authorizerAdministratorIds,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $payload = null,
        ?int $couponId = null
    ): CouponApprovalRequest {
        return DB::transaction(function () use (
            $type,
            $requestedByUserId,
            $requiredApprovals,
            $authorizerAdministratorIds,
            $beforeState,
            $afterState,
            $payload,
            $couponId
        ) {
            $request = CouponApprovalRequest::create([
                'type' => $type,
                'status' => 'pending',
                'requested_by_user_id' => $requestedByUserId,
                'coupon_id' => $couponId,
                'required_approvals' => $requiredApprovals,
                'current_approvals' => 0,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'payload' => $payload,
            ]);

            foreach (array_unique($authorizerAdministratorIds) as $administratorId) {
                CouponApprovalRequestAuthorizer::create([
                    'coupon_approval_request_id' => $request->id,
                    'administrator_id' => (int) $administratorId,
                    'status' => 'pending',
                ]);
            }

            CouponAuditLog::create([
                'type' => $type === 'settings' ? 'configuration' : 'assignment',
                'action' => 'approval_request_created',
                'status' => 'pending',
                'actor_user_id' => $requestedByUserId,
                'coupon_id' => $couponId,
                'coupon_approval_request_id' => $request->id,
                'context' => [
                    'required_approvals' => $requiredApprovals,
                    'authorizer_administrator_ids' => array_values(array_unique($authorizerAdministratorIds)),
                ],
            ]);

            return $request;
        });
    }

    public function logAssignment(
        ?int $actorUserId,
        ?int $couponId,
        array $context,
        string $status = 'completed',
        ?int $approvalRequestId = null
    ): void {
        CouponAuditLog::create([
            'type' => 'assignment',
            'action' => 'assign_coupon',
            'status' => $status,
            'actor_user_id' => $actorUserId,
            'coupon_id' => $couponId,
            'coupon_approval_request_id' => $approvalRequestId,
            'context' => $context,
        ]);
    }

    public function logConfiguration(
        ?int $actorUserId,
        array $before,
        array $after,
        string $status = 'completed',
        ?int $approvalRequestId = null
    ): void {
        CouponAuditLog::create([
            'type' => 'configuration',
            'action' => 'update_coupon_settings',
            'status' => $status,
            'actor_user_id' => $actorUserId,
            'coupon_approval_request_id' => $approvalRequestId,
            'context' => [
                'before' => $before,
                'after' => $after,
            ],
        ]);
    }

    public function assertAssignmentRules(int $amountCents, bool $enforceMaxAssignmentAmount = true): void
    {
        $settings = CouponAdminSettings::singleton();

        if ($enforceMaxAssignmentAmount
            && $settings->max_assignment_amount_cents !== null
            && $amountCents > $settings->max_assignment_amount_cents) {
            throw new \DomainException('El monto supera el máximo permitido por la política de cupones.');
        }

        if ($settings->max_assignments_per_day !== null) {
            $start = now()->startOfDay();
            $count = CouponUser::query()->where('assigned_at', '>=', $start)->count();
            if ($count >= $settings->max_assignments_per_day) {
                throw new \DomainException('Se alcanzó el límite de asignaciones diarias permitidas.');
            }
        }
    }

    /**
     * Crea un cupón independiente con un usuario asignado (importación / flujo legado).
     */
    public function assignCouponToUser(User $user, int $amountCents, bool $sendNotification = true, ?string $code = null, ?int $createdByUserId = null): Coupon
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('El monto del cupón debe ser mayor a cero.');
        }

        $this->assertAssignmentRules($amountCents);

        return DB::transaction(function () use ($user, $amountCents, $sendNotification, $code, $createdByUserId) {
            $coupon = Coupon::create([
                'code' => $code,
                'amount_cents' => $amountCents,
                'remaining_cents' => $amountCents,
                'type' => CouponType::Balance,
                'is_active' => true,
                'approval_status' => CouponApprovalStatus::Active,
                'created_by_user_id' => $createdByUserId,
                'updated_by_user_id' => $createdByUserId,
            ]);

            CouponUser::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'assigned_at' => now(),
            ]);

            if ($sendNotification) {
                $this->notifyUserAssigned($user, $amountCents);
            }

            $this->logAssignment(
                actorUserId: $createdByUserId,
                couponId: $coupon->id,
                context: [
                    'mode' => 'individual',
                    'assigned_user_id' => $user->id,
                    'assigned_user_email' => $user->email,
                    'amount_cents' => $amountCents,
                ]
            );

            return $coupon;
        });
    }

    /**
     * Asigna un beneficiario a un cupón maestro (campaña): crea un cupón hijo con saldo propio.
     *
     * @param  bool  $enforceMaxAssignmentAmount  Si es false, no aplica el tope de monto por política (p. ej. tras pre-aprobación o solicitud multi-firma ejecutada).
     */
    public function assignUserToCampaignCoupon(
        User $user,
        Coupon $parent,
        bool $sendNotification = true,
        ?int $createdByUserId = null,
        bool $enforceMaxAssignmentAmount = true
    ): Coupon {
        if ($parent->parent_coupon_id !== null) {
            throw new \DomainException('Debes elegir un cupón maestro (sin padre).');
        }

        if ($parent->approval_status !== CouponApprovalStatus::Active) {
            throw new \DomainException('El cupón no está autorizado para asignaciones.');
        }

        if (! $parent->is_active) {
            throw new \DomainException('El cupón no está activo.');
        }

        $assignedCount = Coupon::query()->where('parent_coupon_id', $parent->id)->count();
        if ($parent->max_beneficiaries !== null && $assignedCount >= $parent->max_beneficiaries) {
            throw new \DomainException('Se alcanzó el máximo de beneficiarios para este cupón.');
        }

        $exists = CouponUser::query()
            ->where('user_id', $user->id)
            ->whereHas('coupon', function ($q) use ($parent) {
                $q->where('parent_coupon_id', $parent->id);
            })
            ->exists();

        if ($exists) {
            throw new \DomainException('Este usuario ya tiene una asignación de este cupón.');
        }

        $this->assertAssignmentRules($parent->amount_cents, $enforceMaxAssignmentAmount);

        return DB::transaction(function () use ($user, $parent, $sendNotification, $createdByUserId) {
            $child = Coupon::create([
                'parent_coupon_id' => $parent->id,
                'code' => $parent->code,
                'description' => $parent->description,
                'amount_cents' => $parent->amount_cents,
                'remaining_cents' => $parent->amount_cents,
                'type' => CouponType::Balance,
                'is_active' => true,
                'approval_status' => CouponApprovalStatus::Active,
                'created_by_user_id' => $createdByUserId,
                'updated_by_user_id' => $createdByUserId,
            ]);

            CouponUser::create([
                'coupon_id' => $child->id,
                'user_id' => $user->id,
                'assigned_at' => now(),
            ]);

            if ($sendNotification) {
                $this->notifyUserAssigned($user, $parent->amount_cents);
            }

            $this->logAssignment(
                actorUserId: $createdByUserId,
                couponId: $parent->id,
                context: [
                    'mode' => 'campaign',
                    'parent_coupon_id' => $parent->id,
                    'child_coupon_id' => $child->id,
                    'assigned_user_id' => $user->id,
                    'assigned_user_email' => $user->email,
                    'amount_cents' => $parent->amount_cents,
                ]
            );

            return $child;
        });
    }

    private function notifyUserAssigned(User $user, int $amountCents): void
    {
        $formatted = formattedCentsPrice($amountCents);
        $this->notificationService->createNotification(
            $user,
            'coupon_assigned',
            'Saldo a favor disponible',
            "Tienes {$formatted} disponibles en tu cuenta."
        );

        Mail::to($user->email)->send(new CouponAssignedMail($user, $amountCents));
    }

    /**
     * Quita la asignación de un cupón a un usuario (solo si aún no fue utilizado).
     * Si el cupón queda sin asignaciones, se desactiva y el saldo restante pasa a cero.
     */
    public function revokeAssignment(CouponUser $assignment): void
    {
        if ($assignment->used_at !== null) {
            throw new \DomainException('No se puede quitar una asignación que ya fue utilizada.');
        }

        DB::transaction(function () use ($assignment) {
            $coupon = Coupon::query()->whereKey($assignment->coupon_id)->lockForUpdate()->firstOrFail();

            $assignment->delete();

            if ($coupon->couponUsers()->count() === 0) {
                $coupon->remaining_cents = 0;
                $coupon->is_active = false;
                $coupon->save();
            }
        });
    }

    public function authorizePendingCoupon(Coupon $coupon, string $code, int $authorizedByUserId): void
    {
        if ($coupon->parent_coupon_id !== null) {
            throw new \DomainException('Solo se autorizan cupones maestros.');
        }

        if ($coupon->approval_status !== CouponApprovalStatus::PendingAuthorization) {
            throw new \DomainException('Este cupón no está pendiente de autorización.');
        }

        if ($coupon->authorization_code_expires_at && $coupon->authorization_code_expires_at->isPast()) {
            throw new \DomainException('El código de autorización expiró.');
        }

        if (! $coupon->authorization_code_hash || ! Hash::check($code, $coupon->authorization_code_hash)) {
            throw new \DomainException('Código incorrecto.');
        }

        DB::transaction(function () use ($coupon, $authorizedByUserId) {
            $coupon->authorization_code_hash = null;
            $coupon->authorization_code_expires_at = null;
            $coupon->approval_status = CouponApprovalStatus::Active;
            $coupon->is_active = true;
            $coupon->authorized_at = now();
            $coupon->authorized_by_user_id = $authorizedByUserId;
            $coupon->save();
        });
    }
}
