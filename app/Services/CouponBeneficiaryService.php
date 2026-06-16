<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiarySource;
use App\Enums\CouponBeneficiaryStatus;
use App\Mail\CouponBalanceActivatedMail;
use App\Mail\CouponPendingBalanceInvitationMail;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponBeneficiary;
use App\Models\CouponUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class CouponBeneficiaryService
{
    public function __construct(
        private CouponService $couponService,
        private NotificationService $notificationService,
    ) {}

    public function normalizeEmail(string $email): string
    {
        return CouponBeneficiary::normalizeEmail($email);
    }

    /**
     * @param  list<array{email: string, first_name?: ?string, paternal_lastname?: ?string, maternal_lastname?: ?string}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function previewRows(Coupon $parentCoupon, array $rows): array
    {
        $this->assertMasterCoupon($parentCoupon);

        $normalizedRows = $this->normalizeInputRows($rows);
        $existingBeneficiaries = $this->existingBeneficiaryEmails($parentCoupon);
        $legacyAssignedEmails = $this->legacyAssignedEmails($parentCoupon);

        $fileCounts = [];
        foreach ($normalizedRows as $row) {
            $normalized = $this->normalizeEmail((string) ($row['email'] ?? ''));
            if ($normalized !== '') {
                $fileCounts[$normalized] = ($fileCounts[$normalized] ?? 0) + 1;
            }
        }

        $usersByEmail = $this->loadUsersByNormalizedEmails(
            array_keys(array_filter($fileCounts, fn (int $count) => $count === 1))
        );

        $previewRows = [];

        foreach ($normalizedRows as $index => $row) {
            $rowNumber = $index + 1;
            $email = (string) ($row['email'] ?? '');
            $normalized = $email !== '' ? $this->normalizeEmail($email) : '';

            $status = 'valid_pending_user';
            $messages = [];
            $userId = null;
            $editable = true;

            if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                $status = 'invalid_email';
                $messages[] = 'Correo inválido.';
                $editable = false;
            } elseif (($fileCounts[$normalized] ?? 0) > 1) {
                $status = 'duplicate_in_file';
                $messages[] = 'Correo duplicado en el archivo.';
                $editable = false;
            } elseif (isset($existingBeneficiaries[$normalized])) {
                $status = 'already_beneficiary';
                $messages[] = 'Este correo ya está registrado como beneficiario de la campaña.';
                $editable = false;
            } elseif (isset($legacyAssignedEmails[$normalized])) {
                $status = 'already_assigned';
                $messages[] = 'Este correo ya tiene saldo asignado en la campaña.';
                $editable = false;
            } else {
                $user = $usersByEmail[$normalized] ?? null;
                if ($user !== null) {
                    $status = 'valid_registered_user';
                    $userId = $user->id;
                    $messages[] = 'Usuario registrado: se asignará saldo.';
                } else {
                    $status = 'valid_pending_user';
                    $messages[] = 'Pendiente de registro: se guardará como beneficiario pendiente.';
                }
            }

            $previewRows[] = [
                'row_number' => $rowNumber,
                'email' => $email,
                'email_normalized' => $normalized,
                'first_name' => $row['first_name'] ?? null,
                'paternal_lastname' => $row['paternal_lastname'] ?? null,
                'maternal_lastname' => $row['maternal_lastname'] ?? null,
                'status' => $status,
                'user_id' => $userId,
                'messages' => $messages,
                'editable' => $editable,
            ];
        }

        $summary = [
            'total' => count($previewRows),
            'valid_registered_user' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'valid_registered_user')),
            'valid_pending_user' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'valid_pending_user')),
            'invalid_email' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'invalid_email')),
            'duplicate_in_file' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'duplicate_in_file')),
            'already_beneficiary' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'already_beneficiary')),
            'already_assigned' => count(array_filter($previewRows, fn (array $r) => $r['status'] === 'already_assigned')),
            'confirmable' => count(array_filter($previewRows, fn (array $r) => in_array($r['status'], ['valid_registered_user', 'valid_pending_user'], true))),
            'slots_used' => $this->countActiveBeneficiarySlots($parentCoupon),
            'slots_remaining' => $this->remainingBeneficiarySlots($parentCoupon),
        ];

        return [
            'rows' => $previewRows,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<array{email: string, first_name?: ?string, paternal_lastname?: ?string, maternal_lastname?: ?string}>  $rows
     * @return array{
     *     assigned_count: int,
     *     pending_count: int,
     *     skipped_count: int,
     *     errors: list<string>,
     *     created_beneficiary_ids: list<int>,
     *     created_coupon_ids: list<int>,
     *     import_batch_id: string,
     *     invitations_sent_count: int,
     *     invitation_warnings: list<string>
     * }
     */
    public function confirmRows(
        Coupon $parentCoupon,
        array $rows,
        ?User $actor = null,
        CouponBeneficiarySource $source = CouponBeneficiarySource::Manual,
        bool $sendNotifications = true,
        bool $enforceMaxAssignmentAmount = true,
    ): array {
        $this->assertMasterCoupon($parentCoupon);

        $preview = $this->previewRows($parentCoupon, $rows);
        $confirmable = array_values(array_filter(
            $preview['rows'],
            fn (array $r) => in_array($r['status'], ['valid_registered_user', 'valid_pending_user'], true)
        ));

        if ($confirmable === []) {
            return [
                'assigned_count' => 0,
                'pending_count' => 0,
                'skipped_count' => count($preview['rows']),
                'errors' => ['No hay filas válidas para confirmar.'],
                'created_beneficiary_ids' => [],
                'created_coupon_ids' => [],
                'import_batch_id' => (string) Str::uuid(),
                'invitations_sent_count' => 0,
                'invitation_warnings' => [],
            ];
        }

        $remaining = $this->remainingBeneficiarySlots($parentCoupon);
        if ($parentCoupon->max_beneficiaries !== null && count($confirmable) > $remaining) {
            throw new \DomainException(
                'Esta confirmación supera el máximo de beneficiarios. Quedan '.$remaining.' cupo(s) disponible(s) y se intentaron confirmar '.count($confirmable).'.'
            );
        }

        $importBatchId = (string) Str::uuid();
        $assignedCount = 0;
        $pendingCount = 0;
        $skippedCount = 0;
        $errors = [];
        $createdBeneficiaryIds = [];
        $createdCouponIds = [];
        $pendingBeneficiaryIdsForInvitation = [];

        DB::transaction(function () use (
            $parentCoupon,
            $confirmable,
            $actor,
            $source,
            $sendNotifications,
            $enforceMaxAssignmentAmount,
            $importBatchId,
            &$assignedCount,
            &$pendingCount,
            &$skippedCount,
            &$errors,
            &$createdBeneficiaryIds,
            &$createdCouponIds,
            &$pendingBeneficiaryIdsForInvitation,
        ) {
            foreach ($confirmable as $row) {
                $normalized = (string) $row['email_normalized'];

                try {
                    if ($row['status'] === 'valid_registered_user') {
                        $user = User::query()->findOrFail((int) $row['user_id']);
                        $child = $this->couponService->assignUserToCampaignCoupon(
                            $user,
                            $parentCoupon->fresh(),
                            $sendNotifications,
                            $actor?->id,
                            $enforceMaxAssignmentAmount
                        );

                        $beneficiary = $this->upsertBeneficiaryRecord(
                            parent: $parentCoupon,
                            email: (string) $row['email'],
                            normalized: $normalized,
                            firstName: $row['first_name'] ?? null,
                            paternalLastname: $row['paternal_lastname'] ?? null,
                            maternalLastname: $row['maternal_lastname'] ?? null,
                            status: CouponBeneficiaryStatus::Assigned,
                            source: $source,
                            importBatchId: $importBatchId,
                            actor: $actor,
                            user: $user,
                            childCoupon: $child,
                        );

                        $createdBeneficiaryIds[] = $beneficiary->id;
                        $createdCouponIds[] = $child->id;
                        $assignedCount++;

                        $this->auditBeneficiaryEvent(
                            action: 'coupon_beneficiary_assigned',
                            parentCouponId: $parentCoupon->id,
                            actor: $actor,
                            context: $this->auditContextFromBeneficiary($beneficiary)
                        );
                    } else {
                        $beneficiary = $this->upsertBeneficiaryRecord(
                            parent: $parentCoupon,
                            email: (string) $row['email'],
                            normalized: $normalized,
                            firstName: $row['first_name'] ?? null,
                            paternalLastname: $row['paternal_lastname'] ?? null,
                            maternalLastname: $row['maternal_lastname'] ?? null,
                            status: CouponBeneficiaryStatus::PendingUser,
                            source: $source,
                            importBatchId: $importBatchId,
                            actor: $actor,
                        );

                        $createdBeneficiaryIds[] = $beneficiary->id;
                        $pendingCount++;
                        $pendingBeneficiaryIdsForInvitation[] = $beneficiary->id;

                        $this->auditBeneficiaryEvent(
                            action: 'coupon_beneficiary_pending_user',
                            parentCouponId: $parentCoupon->id,
                            actor: $actor,
                            context: $this->auditContextFromBeneficiary($beneficiary)
                        );
                    }
                } catch (\DomainException $e) {
                    $skippedCount++;
                    $errors[] = $row['email'].': '.$e->getMessage();
                }
            }

            $this->auditBeneficiaryEvent(
                action: 'coupon_beneficiaries_confirmed',
                parentCouponId: $parentCoupon->id,
                actor: $actor,
                context: [
                    'parent_coupon_id' => $parentCoupon->id,
                    'import_batch_id' => $importBatchId,
                    'source' => $source->value,
                    'assigned_count' => $assignedCount,
                    'pending_count' => $pendingCount,
                    'skipped_count' => $skippedCount,
                    'actor_user_id' => $actor?->id,
                ]
            );
        });

        $invitationsSentCount = 0;
        $invitationWarnings = [];

        if ($sendNotifications && $pendingBeneficiaryIdsForInvitation !== []) {
            foreach ($pendingBeneficiaryIdsForInvitation as $beneficiaryId) {
                $beneficiary = CouponBeneficiary::query()->find($beneficiaryId);
                if ($beneficiary === null) {
                    continue;
                }

                $invitationResult = $this->sendPendingInvitation($beneficiary, $actor, isResend: false);
                if ($invitationResult['sent']) {
                    $invitationsSentCount++;
                } elseif ($invitationResult['warning'] !== null) {
                    $invitationWarnings[] = $invitationResult['warning'];
                }
            }
        }

        return [
            'assigned_count' => $assignedCount,
            'pending_count' => $pendingCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
            'created_beneficiary_ids' => $createdBeneficiaryIds,
            'created_coupon_ids' => $createdCouponIds,
            'import_batch_id' => $importBatchId,
            'invitations_sent_count' => $invitationsSentCount,
            'invitation_warnings' => $invitationWarnings,
        ];
    }

    public function countActiveBeneficiarySlots(Coupon $parentCoupon): int
    {
        $beneficiaryCount = CouponBeneficiary::query()
            ->activeForParent($parentCoupon->id)
            ->count();

        $trackedChildIds = CouponBeneficiary::query()
            ->activeForParent($parentCoupon->id)
            ->whereNotNull('child_coupon_id')
            ->pluck('child_coupon_id');

        $legacyChildCount = Coupon::query()
            ->where('parent_coupon_id', $parentCoupon->id)
            ->when($trackedChildIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $trackedChildIds))
            ->count();

        return $beneficiaryCount + $legacyChildCount;
    }

    public function remainingBeneficiarySlots(Coupon $parentCoupon): int
    {
        if ($parentCoupon->max_beneficiaries === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $parentCoupon->max_beneficiaries - $this->countActiveBeneficiarySlots($parentCoupon));
    }

    /**
     * Vincula beneficiarios pendientes al usuario tras verificación de email (B2a).
     *
     * @return array{
     *     linked_count: int,
     *     skipped_count: int,
     *     errors: list<string>,
     *     linked_beneficiary_ids: list<int>,
     *     created_coupon_ids: list<int>
     * }
     */
    public function linkPendingBeneficiariesForUser(User $user, bool $dryRun = false): array
    {
        $result = [
            'linked_count' => 0,
            'skipped_count' => 0,
            'errors' => [],
            'linked_beneficiary_ids' => [],
            'created_coupon_ids' => [],
        ];

        if (! $user->hasVerifiedEmail()) {
            return $result;
        }

        $normalizedEmail = $this->normalizeEmail($user->email);

        $pendingIds = CouponBeneficiary::query()
            ->where('status', CouponBeneficiaryStatus::PendingUser)
            ->whereNull('child_coupon_id')
            ->whereNull('cancelled_at')
            ->where('email_normalized', $normalizedEmail)
            ->orderBy('id')
            ->pluck('id');

        foreach ($pendingIds as $beneficiaryId) {
            try {
                $beneficiary = CouponBeneficiary::query()->find($beneficiaryId);
                if ($beneficiary === null) {
                    continue;
                }

                $child = $this->linkPendingBeneficiary($beneficiary, $user, $dryRun);

                if ($child !== null) {
                    $result['linked_count']++;
                    $result['linked_beneficiary_ids'][] = $beneficiary->id;
                    if (! $dryRun) {
                        $result['created_coupon_ids'][] = $child->id;
                    }
                } else {
                    $result['skipped_count']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = 'beneficiary#'.$beneficiaryId.': '.$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Convierte un beneficiario pending_user en asignación efectiva (cupón hijo + coupon_user).
     */
    public function linkPendingBeneficiary(
        CouponBeneficiary $beneficiary,
        User $user,
        bool $dryRun = false,
    ): ?Coupon {
        if (! $user->hasVerifiedEmail()) {
            return null;
        }

        if ($this->normalizeEmail($user->email) !== $beneficiary->email_normalized) {
            if (! $dryRun) {
                $this->auditLinkSkipped($beneficiary, $user, 'email_mismatch');
            }

            return null;
        }

        if ($dryRun) {
            $parent = Coupon::query()->find($beneficiary->parent_coupon_id);
            if ($parent === null || ! $this->canLinkPendingToParent($parent, $user, $beneficiary, dryRun: true)) {
                return null;
            }

            if ($beneficiary->status !== CouponBeneficiaryStatus::PendingUser
                || $beneficiary->child_coupon_id !== null
                || $beneficiary->cancelled_at !== null) {
                return null;
            }

            $existingAssignment = CouponUser::query()
                ->where('user_id', $user->id)
                ->whereHas('coupon', fn ($q) => $q->where('parent_coupon_id', $parent->id))
                ->exists();

            if ($existingAssignment) {
                return null;
            }

            return new Coupon(['id' => 0, 'parent_coupon_id' => $parent->id]);
        }

        return DB::transaction(function () use ($beneficiary, $user) {
            $locked = CouponBeneficiary::query()
                ->whereKey($beneficiary->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            $statusBefore = $locked->status->value;

            if ($locked->status !== CouponBeneficiaryStatus::PendingUser || $locked->child_coupon_id !== null) {
                $this->auditLinkSkipped($locked, $user, 'already_assigned_or_not_pending', $statusBefore);

                return null;
            }

            if ($locked->cancelled_at !== null || $locked->status === CouponBeneficiaryStatus::Cancelled) {
                $this->auditLinkSkipped($locked, $user, 'beneficiary_cancelled', $statusBefore);

                return null;
            }

            if ($this->normalizeEmail($user->email) !== $locked->email_normalized) {
                $this->auditLinkSkipped($locked, $user, 'email_mismatch', $statusBefore);

                return null;
            }

            $parent = Coupon::query()->whereKey($locked->parent_coupon_id)->lockForUpdate()->first();
            if ($parent === null) {
                $this->auditLinkSkipped($locked, $user, 'parent_not_found', $statusBefore);

                return null;
            }

            if (! $this->canLinkPendingToParent($parent, $user, $locked)) {
                return null;
            }

            $existingAssignment = CouponUser::query()
                ->where('user_id', $user->id)
                ->whereHas('coupon', fn ($q) => $q->where('parent_coupon_id', $parent->id))
                ->exists();

            if ($existingAssignment) {
                $this->auditLinkSkipped($locked, $user, 'duplicate_legacy_assignment', $statusBefore);

                return null;
            }

            $child = $this->couponService->createCampaignChildAssignment(
                $user,
                $parent,
                sendNotification: false,
                createdByUserId: $user->id,
            );

            $now = now();
            $locked->fill([
                'user_id' => $user->id,
                'child_coupon_id' => $child->id,
                'status' => CouponBeneficiaryStatus::Assigned,
                'assigned_at' => $now,
                'claimed_at' => $now,
                'activated_at' => $now,
            ]);
            $locked->save();

            $this->auditBeneficiaryEvent(
                action: 'coupon_beneficiary_linked',
                parentCouponId: $parent->id,
                actor: $user,
                context: [
                    'parent_coupon_id' => $parent->id,
                    'beneficiary_id' => $locked->id,
                    'email' => $locked->email,
                    'email_normalized' => $locked->email_normalized,
                    'user_id' => $user->id,
                    'child_coupon_id' => $child->id,
                    'status_before' => $statusBefore,
                    'status_after' => CouponBeneficiaryStatus::Assigned->value,
                    'source' => 'verified_email',
                    'valid_from' => $child->valid_from?->toIso8601String(),
                    'expires_at' => $child->expires_at?->toIso8601String(),
                    'min_purchase_cents' => $child->min_purchase_cents,
                ]
            );

            $beneficiaryForNotify = $locked->fresh();
            DB::afterCommit(function () use ($beneficiaryForNotify, $user, $child) {
                $this->notifyBeneficiaryActivated($beneficiaryForNotify, $user, $child);
            });

            return $child;
        });
    }

    /**
     * @return array{sent: bool, warning: ?string}
     */
    public function sendPendingInvitation(
        CouponBeneficiary $beneficiary,
        ?User $actor,
        bool $isResend = false,
    ): array {
        if (! $beneficiary->isPendingUser() || $beneficiary->user_id !== null || $beneficiary->child_coupon_id !== null) {
            throw new \DomainException('Solo se pueden invitar beneficiarios pendientes de registro.');
        }

        if ($isResend && ! $beneficiary->canResendInvitation()) {
            $availableAt = $beneficiary->resendInvitationAvailableAt();
            throw new \DomainException(
                'Debes esperar antes de reenviar la invitación'
                .($availableAt ? ' (disponible a las '.$availableAt->timezone(config('app.timezone'))->format('H:i').')' : '.')
            );
        }

        $parent = $beneficiary->parentCoupon ?? Coupon::query()->find($beneficiary->parent_coupon_id);
        if ($parent === null) {
            return [
                'sent' => false,
                'warning' => $beneficiary->email.': no se encontró el cupón maestro para enviar la invitación.',
            ];
        }

        try {
            Mail::to($beneficiary->email)->send(new CouponPendingBalanceInvitationMail($beneficiary, $parent));
            $beneficiary->markInvitationSent();

            $this->auditBeneficiaryEvent(
                action: $isResend ? 'coupon_beneficiary_invitation_resent' : 'coupon_beneficiary_invitation_sent',
                parentCouponId: $parent->id,
                actor: $actor,
                context: array_merge($this->auditContextFromBeneficiary($beneficiary->fresh()), [
                    'beneficiary_id' => $beneficiary->id,
                    'invitation_count' => $beneficiary->invitation_count,
                    'last_invitation_sent_at' => $beneficiary->last_invitation_sent_at?->toIso8601String(),
                ])
            );

            return ['sent' => true, 'warning' => null];
        } catch (\Throwable $e) {
            Log::error('Error al enviar invitación de saldo a favor pendiente', [
                'beneficiary_id' => $beneficiary->id,
                'email' => $beneficiary->email,
                'exception' => $e->getMessage(),
            ]);

            $this->auditBeneficiaryEvent(
                action: 'coupon_beneficiary_invitation_failed',
                parentCouponId: $parent->id,
                actor: $actor,
                context: array_merge($this->auditContextFromBeneficiary($beneficiary), [
                    'beneficiary_id' => $beneficiary->id,
                    'is_resend' => $isResend,
                    'error' => $e->getMessage(),
                ])
            );

            return [
                'sent' => false,
                'warning' => $beneficiary->email.': no se pudo enviar la invitación.',
            ];
        }
    }

    public function resendPendingInvitation(CouponBeneficiary $beneficiary, ?User $actor): void
    {
        $result = $this->sendPendingInvitation($beneficiary, $actor, isResend: true);
        if (! $result['sent']) {
            throw new \DomainException($result['warning'] ?? 'No se pudo reenviar la invitación.');
        }
    }

    public function notifyBeneficiaryActivated(
        CouponBeneficiary $beneficiary,
        User $user,
        Coupon $childCoupon,
    ): void {
        if ($beneficiary->hasActivationNotificationBeenSent()) {
            return;
        }

        try {
            Mail::to($user->email)->send(new CouponBalanceActivatedMail($user, $childCoupon));

            $formatted = formattedCentsPrice((int) $childCoupon->amount_cents);
            $this->notificationService->createNotification(
                $user,
                'coupon_balance_activated',
                'Saldo a favor disponible',
                "Tu saldo a favor de {$formatted} ya está disponible en tu cuenta."
            );

            $beneficiary->markActivationNotified();

            $this->auditBeneficiaryEvent(
                action: 'coupon_beneficiary_activation_notified',
                parentCouponId: $beneficiary->parent_coupon_id,
                actor: $user,
                context: array_merge($this->auditContextFromBeneficiary($beneficiary->fresh()), [
                    'beneficiary_id' => $beneficiary->id,
                    'child_coupon_id' => $childCoupon->id,
                    'user_id' => $user->id,
                ])
            );
        } catch (\Throwable $e) {
            Log::error('Error al notificar activación de saldo a favor', [
                'beneficiary_id' => $beneficiary->id,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            $this->auditBeneficiaryEvent(
                action: 'coupon_beneficiary_activation_notify_failed',
                parentCouponId: $beneficiary->parent_coupon_id,
                actor: $user,
                context: array_merge($this->auditContextFromBeneficiary($beneficiary), [
                    'beneficiary_id' => $beneficiary->id,
                    'child_coupon_id' => $childCoupon->id,
                    'error' => $e->getMessage(),
                ])
            );
        }
    }

    private function canLinkPendingToParent(
        Coupon $parent,
        User $user,
        CouponBeneficiary $beneficiary,
        bool $dryRun = false,
    ): bool {
        if ($parent->parent_coupon_id !== null) {
            if (! $dryRun) {
                $this->auditLinkSkipped($beneficiary, $user, 'parent_not_master');
            }

            return false;
        }

        if ($parent->approval_status !== CouponApprovalStatus::Active) {
            if (! $dryRun) {
                $this->auditLinkSkipped($beneficiary, $user, 'parent_not_approved');
            }

            return false;
        }

        if (! $parent->is_active) {
            if (! $dryRun) {
                $this->auditLinkSkipped($beneficiary, $user, 'parent_inactive');
            }

            return false;
        }

        return true;
    }

    private function auditLinkSkipped(
        CouponBeneficiary $beneficiary,
        User $user,
        string $reason,
        ?string $statusBefore = null,
    ): void {
        $this->auditBeneficiaryEvent(
            action: 'coupon_beneficiary_link_skipped',
            parentCouponId: $beneficiary->parent_coupon_id,
            actor: $user,
            context: [
                'parent_coupon_id' => $beneficiary->parent_coupon_id,
                'beneficiary_id' => $beneficiary->id,
                'email' => $beneficiary->email,
                'email_normalized' => $beneficiary->email_normalized,
                'user_id' => $user->id,
                'status_before' => $statusBefore ?? $beneficiary->status->value,
                'status_after' => $beneficiary->status->value,
                'reason' => $reason,
                'source' => 'verified_email',
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{email: string, first_name: ?string, paternal_lastname: ?string, maternal_lastname: ?string}>
     */
    public function validateInputRows(array $rows): array
    {
        Validator::make(
            ['rows' => $rows],
            [
                'rows' => ['required', 'array', 'max:5000'],
                'rows.*.email' => ['required', 'string', 'max:255'],
                'rows.*.first_name' => ['nullable', 'string', 'max:255'],
                'rows.*.paternal_lastname' => ['nullable', 'string', 'max:255'],
                'rows.*.maternal_lastname' => ['nullable', 'string', 'max:255'],
            ]
        )->validate();

        return $this->normalizeInputRows($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{email: string, first_name: ?string, paternal_lastname: ?string, maternal_lastname: ?string}>
     */
    private function normalizeInputRows(array $rows): array
    {
        return array_values(array_map(function (array $row) {
            return [
                'email' => trim((string) ($row['email'] ?? '')),
                'first_name' => isset($row['first_name']) && trim((string) $row['first_name']) !== ''
                    ? trim((string) $row['first_name'])
                    : null,
                'paternal_lastname' => isset($row['paternal_lastname']) && trim((string) $row['paternal_lastname']) !== ''
                    ? trim((string) $row['paternal_lastname'])
                    : null,
                'maternal_lastname' => isset($row['maternal_lastname']) && trim((string) $row['maternal_lastname']) !== ''
                    ? trim((string) $row['maternal_lastname'])
                    : null,
            ];
        }, $rows));
    }

    /**
     * @return array<string, true>
     */
    private function existingBeneficiaryEmails(Coupon $parentCoupon): array
    {
        return CouponBeneficiary::query()
            ->activeForParent($parentCoupon->id)
            ->pluck('email_normalized')
            ->mapWithKeys(fn (string $email) => [$email => true])
            ->all();
    }

    /**
     * @return array<string, true>
     */
    private function legacyAssignedEmails(Coupon $parentCoupon): array
    {
        $emails = [];

        $trackedChildIds = CouponBeneficiary::query()
            ->activeForParent($parentCoupon->id)
            ->whereNotNull('child_coupon_id')
            ->pluck('child_coupon_id')
            ->all();

        $children = Coupon::query()
            ->where('parent_coupon_id', $parentCoupon->id)
            ->with('couponUsers.user:id,email')
            ->get();

        foreach ($children as $child) {
            if (in_array($child->id, $trackedChildIds, true)) {
                continue;
            }

            $assignment = $child->couponUsers->first();
            if ($assignment?->user?->email) {
                $emails[$this->normalizeEmail($assignment->user->email)] = true;
            }
        }

        return $emails;
    }

    /**
     * @param  list<string>  $normalizedEmails
     * @return array<string, User>
     */
    private function loadUsersByNormalizedEmails(array $normalizedEmails): array
    {
        if ($normalizedEmails === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedEmails), '?'));
        $users = User::query()
            ->select(['id', 'email', 'name', 'paternal_lastname', 'maternal_lastname'])
            ->whereRaw('LOWER(TRIM(email)) IN ('.$placeholders.')', $normalizedEmails)
            ->get();

        $map = [];
        foreach ($users as $user) {
            $map[$this->normalizeEmail($user->email)] = $user;
        }

        return $map;
    }

    private function assertMasterCoupon(Coupon $parentCoupon): void
    {
        if ($parentCoupon->parent_coupon_id !== null) {
            throw new \DomainException('Debes elegir un cupón maestro (sin padre).');
        }
    }

    private function upsertBeneficiaryRecord(
        Coupon $parent,
        string $email,
        string $normalized,
        ?string $firstName,
        ?string $paternalLastname,
        ?string $maternalLastname,
        CouponBeneficiaryStatus $status,
        CouponBeneficiarySource $source,
        string $importBatchId,
        ?User $actor,
        ?User $user = null,
        ?Coupon $childCoupon = null,
    ): CouponBeneficiary {
        $existing = CouponBeneficiary::query()
            ->where('parent_coupon_id', $parent->id)
            ->where('email_normalized', $normalized)
            ->first();

        $payload = [
            'parent_coupon_id' => $parent->id,
            'child_coupon_id' => $childCoupon?->id,
            'user_id' => $user?->id,
            'email' => $email,
            'email_normalized' => $normalized,
            'first_name' => $firstName,
            'paternal_lastname' => $paternalLastname,
            'maternal_lastname' => $maternalLastname,
            'status' => $status,
            'source' => $source,
            'import_batch_id' => $importBatchId,
            'assigned_at' => $status === CouponBeneficiaryStatus::Assigned ? now() : null,
            'updated_by_user_id' => $actor?->id,
        ];

        if ($existing !== null) {
            $existing->fill($payload);
            if ($existing->created_by_user_id === null) {
                $existing->created_by_user_id = $actor?->id;
            }
            $existing->save();

            return $existing->fresh();
        }

        return CouponBeneficiary::create(array_merge($payload, [
            'created_by_user_id' => $actor?->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditContextFromBeneficiary(CouponBeneficiary $beneficiary): array
    {
        return [
            'parent_coupon_id' => $beneficiary->parent_coupon_id,
            'email' => $beneficiary->email,
            'email_normalized' => $beneficiary->email_normalized,
            'user_id' => $beneficiary->user_id,
            'child_coupon_id' => $beneficiary->child_coupon_id,
            'status' => $beneficiary->status->value,
            'source' => $beneficiary->source->value,
            'import_batch_id' => $beneficiary->import_batch_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditBeneficiaryEvent(string $action, int $parentCouponId, ?User $actor, array $context): void
    {
        CouponAuditLog::create([
            'type' => 'assignment',
            'action' => $action,
            'status' => 'completed',
            'actor_user_id' => $actor?->id,
            'coupon_id' => $parentCouponId,
            'context' => $context,
        ]);
    }
}
