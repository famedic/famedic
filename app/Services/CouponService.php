<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Mail\CouponAssignedMail;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\CouponAuditLog;
use App\Models\CouponAmountApprovalRule;
use App\Models\CouponBeneficiaryApprovalRule;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class CouponService
{
    private NotificationService $notificationService;

    public function __construct(
        NotificationService $notificationService
    ) {
        $this->notificationService = $notificationService;
    }

    public function getUserBalance(int $userId): int
    {
        return (int) $this->assignedCheckoutCouponsQuery($userId)->sum('coupons.remaining_cents');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Coupon>
     */
    private function assignedCheckoutCouponsQuery(int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return Coupon::query()
            ->where('coupons.is_active', true)
            ->where('coupons.remaining_cents', '>', 0)
            ->whereIn('coupons.type', [CouponType::Balance, CouponType::Coupon])
            ->where('coupons.approval_status', CouponApprovalStatus::Active)
            ->withinValidityWindow()
            ->join('coupon_user', 'coupon_user.coupon_id', '=', 'coupons.id')
            ->where('coupon_user.user_id', $userId)
            ->whereNull('coupon_user.used_at');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     remaining_cents: int,
     *     code: ?string,
     *     valid_from: ?string,
     *     expires_at: ?string,
     *     min_purchase_cents: ?int,
     *     formatted_min_purchase: ?string,
     *     validity_status: string
     * }>
     */
    public function getAvailableCoupons(int $userId): \Illuminate\Support\Collection
    {
        return $this->assignedCheckoutCouponsQuery($userId)
            ->with([
                'concept:id,title',
                'parentCoupon:id,coupon_concept_id,concept_other',
                'parentCoupon.concept:id,title',
            ])
            ->select([
                'coupons.id',
                'coupons.type',
                'coupons.remaining_cents',
                'coupons.code',
                'coupons.valid_from',
                'coupons.expires_at',
                'coupons.min_purchase_cents',
            ])
            ->orderBy('coupons.id')
            ->get()
            ->map(fn (Coupon $coupon) => $this->mapCouponForCheckout($coupon));
    }

    /**
     * Presentación vacía cuando falla la consulta de saldo (p. ej. carrito).
     *
     * @return array<string, mixed>
     */
    public function emptyCheckoutCreditPresentation(int $cartTotalCents = 0): array
    {
        return [
            'total_balance_cents' => 0,
            'applicable_balance_cents' => 0,
            'conditional_balance_cents' => 0,
            'applicable_coupons_count' => 0,
            'conditional_coupons_count' => 0,
            'scheduled_coupons_count' => 0,
            'best_coupon' => null,
            'coupons' => [],
            'balanceCouponsCents' => 0,
            'formattedBalanceCoupons' => null,
            'formattedApplicableBalance' => null,
            'availableBalanceCoupons' => [],
            'cartTotalCents' => max(0, $cartTotalCents),
        ];
    }

    /**
     * Presentación enriquecida de créditos para carrito/checkout (sin alterar reglas de aplicación).
     *
     * @return array{
     *     total_balance_cents: int,
     *     applicable_balance_cents: int,
     *     conditional_balance_cents: int,
     *     applicable_coupons_count: int,
     *     conditional_coupons_count: int,
     *     scheduled_coupons_count: int,
     *     best_coupon: ?array<string, mixed>,
     *     coupons: array<int, array<string, mixed>>,
     *     balanceCouponsCents: int,
     *     formattedBalanceCoupons: ?string,
     *     formattedApplicableBalance: ?string,
     *     availableBalanceCoupons: array<int, array<string, mixed>>,
     *     cartTotalCents: int
     * }
     */
    public function buildCheckoutCreditPresentation(int $userId, int $cartTotalCents): array
    {
        $cartTotalCents = max(0, $cartTotalCents);

        $couponModels = $this->assignedCheckoutCouponsQuery($userId)
            ->with([
                'concept:id,title',
                'parentCoupon:id,coupon_concept_id,concept_other',
                'parentCoupon.concept:id,title',
            ])
            ->orderBy('coupons.id')
            ->get(['coupons.*']);

        $coupons = [];
        foreach ($couponModels as $coupon) {
            $coupons[] = $this->mapCouponForCheckoutPresentation($coupon, $cartTotalCents);
        }

        $recommendedId = $this->pickRecommendedCouponId($coupons);
        $coupons = array_map(function (array $row) use ($recommendedId) {
            $row['is_recommended'] = $recommendedId !== null && $row['id'] === $recommendedId;

            return $row;
        }, $coupons);
        $coupons = $this->sortCouponsForPatientDisplay($coupons);

        $applicableCoupons = array_values(array_filter($coupons, fn (array $c) => $c['is_applicable']));
        $conditionalCoupons = array_values(array_filter(
            $coupons,
            fn (array $c) => in_array($c['reason'], ['below_minimum', 'balance_too_large'], true)
        ));
        $scheduledCoupons = array_values(array_filter(
            $coupons,
            fn (array $c) => $c['reason'] === 'scheduled'
        ));

        $totalBalanceCents = (int) array_sum(array_column($coupons, 'remaining_cents'));
        $applicableBalanceCents = (int) array_sum(array_column($applicableCoupons, 'remaining_cents'));
        $conditionalBalanceCents = (int) array_sum(array_column($conditionalCoupons, 'remaining_cents'));

        $bestCoupon = null;
        if ($recommendedId !== null) {
            foreach ($coupons as $coupon) {
                if ($coupon['id'] === $recommendedId) {
                    $bestCoupon = $coupon;
                    break;
                }
            }
        }

        $legacyCoupons = array_map(
            fn (array $c) => $this->stripPresentationOnlyFields($c),
            $coupons
        );

        return [
            'total_balance_cents' => $totalBalanceCents,
            'applicable_balance_cents' => $applicableBalanceCents,
            'conditional_balance_cents' => $conditionalBalanceCents,
            'applicable_coupons_count' => count($applicableCoupons),
            'conditional_coupons_count' => count($conditionalCoupons),
            'scheduled_coupons_count' => count($scheduledCoupons),
            'best_coupon' => $bestCoupon,
            'coupons' => $coupons,
            'balanceCouponsCents' => $totalBalanceCents,
            'formattedBalanceCoupons' => $totalBalanceCents > 0 ? formattedCentsPrice($totalBalanceCents) : null,
            'formattedApplicableBalance' => $applicableBalanceCents > 0
                ? formattedCentsPrice($applicableBalanceCents)
                : null,
            'availableBalanceCoupons' => $legacyCoupons,
            'cartTotalCents' => $cartTotalCents,
        ];
    }

    /**
     * @return array{
     *     balanceCouponsCents: int,
     *     formattedBalanceCoupons: ?string,
     *     availableBalanceCoupons: array<int, array<string, mixed>>,
     *     cartTotalCents: int
     * }
     */
    public function buildPatientBalancePresentation(int $userId, int $cartTotalCents): array
    {
        return $this->buildCheckoutCreditPresentation($userId, $cartTotalCents);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCouponForCheckoutPresentation(Coupon $coupon, int $cartTotalCents): array
    {
        $base = $this->mapCouponForCheckout($coupon);
        $reason = $this->resolveCouponPresentationReason($coupon, $cartTotalCents);
        $missingForMinimum = null;

        if ($reason === 'below_minimum' && $coupon->min_purchase_cents !== null) {
            $missingForMinimum = max(0, (int) $coupon->min_purchase_cents - $cartTotalCents);
        }

        return array_merge($base, [
            'formatted_remaining' => formattedCentsPrice((int) $coupon->remaining_cents),
            'is_applicable' => $reason === 'applicable',
            'is_recommended' => false,
            'reason' => $reason,
            'label' => $this->couponPresentationLabel($reason, $coupon->type),
            'missing_for_minimum_cents' => $missingForMinimum,
            'formatted_missing_for_minimum' => $missingForMinimum !== null && $missingForMinimum > 0
                ? formattedCentsPrice($missingForMinimum)
                : null,
        ]);
    }

    private function resolveCouponPresentationReason(Coupon $coupon, int $cartTotalCents): string
    {
        if ($coupon->isNotYetValid()) {
            return 'scheduled';
        }

        if ($coupon->isExpired()) {
            return 'expired';
        }

        if (! $coupon->meetsMinimumPurchase($cartTotalCents)) {
            return 'below_minimum';
        }

        if ($coupon->type === CouponType::Coupon) {
            return 'applicable';
        }

        if ($coupon->remaining_cents > $cartTotalCents) {
            return 'balance_too_large';
        }

        return 'applicable';
    }

    private function couponPresentationLabel(string $reason, ?CouponType $type = null): string
    {
        return match ($reason) {
            'applicable' => $type === CouponType::Coupon ? 'Cupón aplicable' : 'Aplicable ahora',
            'below_minimum' => 'Requiere compra mínima',
            'balance_too_large' => 'Saldo mayor al total',
            'scheduled' => 'Disponible próximamente',
            'expired' => 'Vencido',
            default => 'No disponible',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $coupons
     */
    private function pickRecommendedCouponId(array $coupons): ?int
    {
        $applicable = array_values(array_filter($coupons, fn (array $c) => $c['is_applicable']));

        if ($applicable === []) {
            return null;
        }

        usort($applicable, function (array $a, array $b) {
            $aExpires = $a['expires_at'] !== null ? strtotime((string) $a['expires_at']) : PHP_INT_MAX;
            $bExpires = $b['expires_at'] !== null ? strtotime((string) $b['expires_at']) : PHP_INT_MAX;

            if ($aExpires !== $bExpires) {
                return $aExpires <=> $bExpires;
            }

            if ($a['remaining_cents'] !== $b['remaining_cents']) {
                return $b['remaining_cents'] <=> $a['remaining_cents'];
            }

            return $a['id'] <=> $b['id'];
        });

        return (int) $applicable[0]['id'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $coupons
     * @return array<int, array<string, mixed>>
     */
    private function sortCouponsForPatientDisplay(array $coupons): array
    {
        $priority = [
            'applicable' => 0,
            'below_minimum' => 1,
            'balance_too_large' => 2,
            'scheduled' => 3,
            'expired' => 4,
        ];

        usort($coupons, function (array $a, array $b) use ($priority) {
            $aPriority = $priority[$a['reason']] ?? 99;
            $bPriority = $priority[$b['reason']] ?? 99;

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            if ($a['is_recommended'] !== $b['is_recommended']) {
                return $a['is_recommended'] ? -1 : 1;
            }

            return $a['id'] <=> $b['id'];
        });

        return $coupons;
    }

    /**
     * @param  array<string, mixed>  $coupon
     * @return array<string, mixed>
     */
    private function stripPresentationOnlyFields(array $coupon): array
    {
        unset(
            $coupon['is_applicable'],
            $coupon['is_recommended'],
            $coupon['reason'],
            $coupon['label'],
            $coupon['missing_for_minimum_cents'],
            $coupon['formatted_missing_for_minimum'],
            $coupon['formatted_remaining'],
        );

        return $coupon;
    }

    /**
     * @return array{
     *     id: int,
     *     remaining_cents: int,
     *     code: ?string,
     *     valid_from: ?string,
     *     expires_at: ?string,
     *     min_purchase_cents: ?int,
     *     formatted_min_purchase: ?string,
     *     validity_status: string
     * }
     */
    public function mapCouponForCheckout(Coupon $coupon): array
    {
        return [
            'id' => (int) $coupon->id,
            'type' => $coupon->type->value,
            'type_label' => $coupon->type->label(),
            'remaining_cents' => (int) $coupon->remaining_cents,
            'code' => $coupon->code,
            'concept' => $this->resolveConceptLabelForCheckout($coupon),
            'valid_from' => $coupon->valid_from?->toIso8601String(),
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'min_purchase_cents' => $coupon->min_purchase_cents !== null
                ? (int) $coupon->min_purchase_cents
                : null,
            'formatted_min_purchase' => $coupon->formatted_min_purchase,
            'validity_status' => $coupon->validity_status,
        ];
    }

    private function resolveConceptLabelForCheckout(Coupon $coupon): ?string
    {
        $other = trim((string) ($coupon->concept_other ?? ''));
        if ($other !== '') {
            return $other;
        }

        $coupon->loadMissing(
            'concept:id,title',
            'parentCoupon:id,coupon_concept_id,concept_other',
            'parentCoupon.concept:id,title',
        );

        if ($coupon->concept?->title) {
            return $coupon->concept->title;
        }

        $parent = $coupon->parentCoupon;
        if ($parent === null) {
            return null;
        }

        $parentOther = trim((string) ($parent->concept_other ?? ''));
        if ($parentOther !== '') {
            return $parentOther;
        }

        return $parent->concept?->title;
    }

    public function resolveRequiredApprovals(int $amountCents, int $beneficiariesCount): int
    {
        $settings = CouponAdminSettings::singleton();

        $approvalsByAmount = 0;
        $amountRule = CouponAmountApprovalRule::query()
            ->where('min_amount_cents', '<=', $amountCents)
            ->where(function ($q) use ($amountCents) {
                $q->whereNull('max_amount_cents')
                    ->orWhere('max_amount_cents', '>=', $amountCents);
            })
            ->orderByDesc('required_approvals')
            ->first();
        if ($amountRule) {
            $approvalsByAmount = (int) $amountRule->required_approvals;
        } elseif (
            $settings->amount_threshold_cents !== null &&
            $settings->required_approvals_by_amount > 0 &&
            $amountCents >= $settings->amount_threshold_cents
        ) {
            // Compatibilidad: umbral único legado.
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

            $couponUser = CouponUser::create([
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

            app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)
                ->creditAssigned($coupon, $couponUser, $user, 'individual');

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
            throw new \DomainException('Esta campaña está inactiva y no permite nuevas asignaciones.');
        }

        $beneficiaryService = app(CouponBeneficiaryService::class);
        if ($parent->max_beneficiaries !== null && $beneficiaryService->remainingBeneficiarySlots($parent) <= 0) {
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

        $normalizedEmail = CouponBeneficiary::normalizeEmail($user->email);
        $pendingDuplicate = CouponBeneficiary::query()
            ->activeForParent($parent->id)
            ->where('email_normalized', $normalizedEmail)
            ->exists();

        if ($pendingDuplicate) {
            throw new \DomainException('Este correo ya está registrado como beneficiario de la campaña.');
        }

        $this->assertAssignmentRules($parent->amount_cents, $enforceMaxAssignmentAmount);

        return DB::transaction(fn () => $this->createCampaignChildAssignment(
            $user,
            $parent,
            $sendNotification,
            $createdByUserId,
        ));
    }

    /**
     * Crea cupón hijo de campaña y fila coupon_user sin validaciones de cupo/duplicado en beneficiarios.
     * Usado por assignUserToCampaignCoupon y por vinculación de pendientes (B2a).
     * El caller debe envolver en transacción si lo requiere.
     */
    public function createCampaignChildAssignment(
        User $user,
        Coupon $parent,
        bool $sendNotification = true,
        ?int $createdByUserId = null,
        bool $skipActiveCampaignCreditAssigned = false,
    ): Coupon {
        $child = Coupon::create([
            'parent_coupon_id' => $parent->id,
            'code' => $parent->code,
            'description' => $parent->description,
            'coupon_concept_id' => $parent->coupon_concept_id,
            'concept_other' => $parent->concept_other,
            'amount_cents' => $parent->amount_cents,
            'remaining_cents' => $parent->amount_cents,
            'valid_from' => $parent->valid_from,
            'expires_at' => $parent->expires_at,
            'min_purchase_cents' => $parent->min_purchase_cents,
            'type' => $parent->type ?? CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
            'created_by_user_id' => $createdByUserId,
            'updated_by_user_id' => $createdByUserId,
        ]);

        $couponUser = CouponUser::create([
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
                'valid_from' => $parent->valid_from?->toIso8601String(),
                'expires_at' => $parent->expires_at?->toIso8601String(),
                'min_purchase_cents' => $parent->min_purchase_cents,
            ]
        );

        if (! $skipActiveCampaignCreditAssigned) {
            app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)
                ->creditAssigned($child, $couponUser, $user, 'campaign');
        }

        return $child;
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
     * Revoca un crédito asignado no usado (o restaurado tras reverso de pedido).
     * Desactiva el cupón hijo, sincroniza beneficiario y audita. Conserva coupon_user e historial.
     */
    public function revokeAssignment(CouponUser $assignment, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($assignment, $actor, $reason) {
            $assignment = CouponUser::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $assignment->loadMissing('user');
            $coupon = Coupon::query()->whereKey($assignment->coupon_id)->lockForUpdate()->firstOrFail();

            $activeTransaction = CouponTransaction::query()
                ->where('coupon_id', $coupon->id)
                ->whereNull('reversed_at')
                ->exists();

            if ($activeTransaction) {
                $this->logRevocationRejected($coupon, $assignment, $actor, 'active_transaction', $reason);

                throw new \DomainException(
                    'Este crédito ya fue utilizado. Para restaurarlo, cancela el pedido relacionado.'
                );
            }

            if ($assignment->used_at !== null) {
                $this->logRevocationRejected($coupon, $assignment, $actor, 'assignment_used', $reason);

                throw new \DomainException(
                    'Este crédito ya fue utilizado. Para restaurarlo, cancela el pedido relacionado.'
                );
            }

            $hadReversedTransaction = CouponTransaction::query()
                ->where('coupon_id', $coupon->id)
                ->whereNotNull('reversed_at')
                ->exists();

            $remainingBefore = (int) $coupon->remaining_cents;

            $coupon->remaining_cents = 0;
            $coupon->is_active = false;
            $coupon->updated_by_user_id = $actor?->id;
            $coupon->save();

            $beneficiary = $this->cancelBeneficiaryForRevokedChildCoupon($coupon, $actor);

            CouponAuditLog::create([
                'type' => 'assignment',
                'action' => 'coupon_assignment_revoked',
                'status' => 'completed',
                'actor_user_id' => $actor?->id,
                'coupon_id' => $coupon->parent_coupon_id ?? $coupon->id,
                'context' => [
                    'coupon_id' => $coupon->id,
                    'parent_coupon_id' => $coupon->parent_coupon_id,
                    'child_coupon_id' => $coupon->parent_coupon_id !== null ? $coupon->id : null,
                    'coupon_user_id' => $assignment->id,
                    'beneficiary_id' => $beneficiary?->id,
                    'user_id' => $assignment->user_id,
                    'email' => $assignment->user?->email,
                    'amount_cents' => (int) $coupon->amount_cents,
                    'remaining_cents_before' => $remainingBefore,
                    'reason' => $reason ?? 'admin_manual_revocation',
                    'actor_user_id' => $actor?->id,
                    'had_reversed_transaction' => $hadReversedTransaction,
                ],
            ]);

            $user = $assignment->user;
            if ($user !== null) {
                app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)->creditRevoked(
                    $coupon,
                    $assignment,
                    $user,
                    $remainingBefore,
                    $actor?->id,
                    $reason ?? 'admin_manual_revocation',
                );
            }
        });
    }

    private function cancelBeneficiaryForRevokedChildCoupon(Coupon $coupon, ?User $actor): ?CouponBeneficiary
    {
        if ($coupon->parent_coupon_id === null) {
            return null;
        }

        $beneficiary = CouponBeneficiary::query()
            ->where('child_coupon_id', $coupon->id)
            ->whereNull('cancelled_at')
            ->lockForUpdate()
            ->first();

        if ($beneficiary === null) {
            return null;
        }

        if ($beneficiary->status !== CouponBeneficiaryStatus::Cancelled) {
            $beneficiary->status = CouponBeneficiaryStatus::Cancelled;
            $beneficiary->cancelled_at = now();
            $beneficiary->updated_by_user_id = $actor?->id;
            $beneficiary->save();
        }

        return $beneficiary;
    }

    private function logRevocationRejected(
        Coupon $coupon,
        CouponUser $assignment,
        ?User $actor,
        string $blockedReason,
        ?string $reason
    ): void {
        CouponAuditLog::create([
            'type' => 'assignment',
            'action' => 'coupon_revocation_rejected',
            'status' => 'completed',
            'actor_user_id' => $actor?->id,
            'coupon_id' => $coupon->parent_coupon_id ?? $coupon->id,
            'context' => [
                'coupon_id' => $coupon->id,
                'parent_coupon_id' => $coupon->parent_coupon_id,
                'child_coupon_id' => $coupon->parent_coupon_id !== null ? $coupon->id : null,
                'coupon_user_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'blocked_reason' => $blockedReason,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
            ],
        ]);
    }

    /**
     * @return array{
     *     children_count: int,
     *     beneficiaries_count: int,
     *     assignments_count: int,
     *     transactions_count: int,
     *     audit_logs_count: int,
     *     has_activity: bool
     * }
     */
    public function getCampaignActivitySummary(Coupon $coupon): array
    {
        $this->assertMasterCampaignCoupon($coupon);

        $couponId = (int) $coupon->id;
        $childIds = Coupon::query()->where('parent_coupon_id', $couponId)->pluck('id');

        $childrenCount = $childIds->count();
        $beneficiariesCount = (int) CouponBeneficiary::query()->where('parent_coupon_id', $couponId)->count();
        $directAssignmentsCount = (int) CouponUser::query()->where('coupon_id', $couponId)->count();
        $childAssignmentsCount = $childIds->isEmpty()
            ? 0
            : (int) CouponUser::query()->whereIn('coupon_id', $childIds)->count();
        $assignmentsCount = $directAssignmentsCount + $childAssignmentsCount;

        $directTransactionsCount = (int) CouponTransaction::query()->where('coupon_id', $couponId)->count();
        $childTransactionsCount = $childIds->isEmpty()
            ? 0
            : (int) CouponTransaction::query()->whereIn('coupon_id', $childIds)->count();
        $transactionsCount = $directTransactionsCount + $childTransactionsCount;

        $auditLogsCount = (int) CouponAuditLog::query()->where('coupon_id', $couponId)->count();

        $hasActivity = $childrenCount > 0
            || $assignmentsCount > 0
            || $beneficiariesCount > 0
            || $transactionsCount > 0;

        return [
            'children_count' => $childrenCount,
            'beneficiaries_count' => $beneficiariesCount,
            'assignments_count' => $assignmentsCount,
            'transactions_count' => $transactionsCount,
            'audit_logs_count' => $auditLogsCount,
            'has_activity' => $hasActivity,
        ];
    }

    public function campaignHasActivity(Coupon $coupon): bool
    {
        return $this->getCampaignActivitySummary($coupon)['has_activity'];
    }

    public function deactivateCampaign(Coupon $coupon, ?User $actor = null, ?string $reason = null): void
    {
        $this->assertMasterCampaignCoupon($coupon);

        if (! $coupon->is_active) {
            return;
        }

        DB::transaction(function () use ($coupon, $actor, $reason) {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                return;
            }

            $summary = $this->getCampaignActivitySummary($locked);

            $locked->is_active = false;
            $locked->updated_by_user_id = $actor?->id;
            $locked->save();

            CouponAuditLog::create([
                'type' => 'campaign',
                'action' => 'coupon_campaign_deactivated',
                'status' => 'completed',
                'actor_user_id' => $actor?->id,
                'coupon_id' => $locked->id,
                'context' => array_merge($this->campaignAuditContext($locked), $summary, [
                    'actor_user_id' => $actor?->id,
                    'reason' => $reason ?? 'admin_manual_deactivation',
                ]),
            ]);
        });
    }

    public function deleteCampaignIfUnused(Coupon $coupon, ?User $actor = null): void
    {
        $this->assertMasterCampaignCoupon($coupon);

        $summary = $this->getCampaignActivitySummary($coupon);

        if ($summary['has_activity']) {
            $this->logCampaignDeleteRejected($coupon, $actor, $summary);

            throw new \DomainException(
                'No se puede eliminar esta campaña porque ya tiene actividad. Puedes desactivarla para evitar nuevas asignaciones.'
            );
        }

        DB::transaction(function () use ($coupon, $actor, $summary) {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

            CouponAuditLog::create([
                'type' => 'campaign',
                'action' => 'coupon_campaign_deleted',
                'status' => 'completed',
                'actor_user_id' => $actor?->id,
                'coupon_id' => $locked->id,
                'context' => array_merge($this->campaignAuditContext($locked), $summary, [
                    'actor_user_id' => $actor?->id,
                ]),
            ]);

            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function logCampaignDeleteRejected(Coupon $coupon, ?User $actor, array $summary): void
    {
        CouponAuditLog::create([
            'type' => 'campaign',
            'action' => 'coupon_campaign_delete_rejected',
            'status' => 'completed',
            'actor_user_id' => $actor?->id,
            'coupon_id' => $coupon->id,
            'context' => array_merge($this->campaignAuditContext($coupon), $summary, [
                'actor_user_id' => $actor?->id,
            ]),
        ]);
    }

    /**
     * @return array{coupon_id: int, name: ?string, amount_cents: int}
     */
    private function campaignAuditContext(Coupon $coupon): array
    {
        return [
            'coupon_id' => (int) $coupon->id,
            'name' => $coupon->code ?? $coupon->description,
            'amount_cents' => (int) $coupon->amount_cents,
        ];
    }

    private function assertMasterCampaignCoupon(Coupon $coupon): void
    {
        if ($coupon->parent_coupon_id !== null) {
            throw new \DomainException('Esta acción solo aplica a cupones maestro (campaña).');
        }
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
