<?php

namespace App\Console\Commands;

use App\Models\CouponBeneficiary;
use App\Services\CouponBeneficiaryService;
use Illuminate\Console\Command;

class SendPendingBeneficiaryInvitations extends Command
{
    protected $signature = 'coupons:send-pending-beneficiary-invitations
                            {--dry-run : Simula el envío sin modificar datos}
                            {--email= : Procesar solo este correo}
                            {--limit=100 : Máximo de beneficiarios a procesar}
                            {--resend : Incluir reenvíos (respeta cooldown de 10 min)}
                            {--activations : Reintentar notificaciones de activación no enviadas}';

    protected $description = 'Envía invitaciones a beneficiarios pending_user y/o reintenta notificaciones de activación.';

    public function handle(CouponBeneficiaryService $beneficiaryService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $emailFilter = $this->option('email');
        $limit = max(1, (int) $this->option('limit'));
        $includeResend = (bool) $this->option('resend');
        $activationsOnly = (bool) $this->option('activations');

        $invitationsSent = 0;
        $invitationSkipped = 0;
        $activationsSent = 0;
        $warnings = [];

        if (! $activationsOnly) {
            $query = CouponBeneficiary::query()
                ->where('status', 'pending_user')
                ->whereNull('child_coupon_id')
                ->whereNull('cancelled_at')
                ->when($emailFilter, fn ($q) => $q->where(
                    'email_normalized',
                    CouponBeneficiary::normalizeEmail((string) $emailFilter)
                ))
                ->when(! $includeResend, fn ($q) => $q->whereNull('invitation_sent_at'))
                ->orderBy('id')
                ->limit($limit);

            foreach ($query->get() as $beneficiary) {
                if ($dryRun) {
                    if ($includeResend && ! $beneficiary->canResendInvitation()) {
                        $invitationSkipped++;
                        continue;
                    }
                    $invitationsSent++;
                    $this->line("[DRY-RUN] Invitaría a {$beneficiary->email} (beneficiario #{$beneficiary->id})");
                    continue;
                }

                if ($includeResend && $beneficiary->invitation_sent_at !== null) {
                    try {
                        $beneficiaryService->resendPendingInvitation($beneficiary, actor: null);
                        $invitationsSent++;
                    } catch (\DomainException $e) {
                        $invitationSkipped++;
                        $warnings[] = $beneficiary->email.': '.$e->getMessage();
                    }
                    continue;
                }

                $result = $beneficiaryService->sendPendingInvitation($beneficiary, actor: null, isResend: false);
                if ($result['sent']) {
                    $invitationsSent++;
                } else {
                    $invitationSkipped++;
                    if ($result['warning']) {
                        $warnings[] = $result['warning'];
                    }
                }
            }
        }

        if ($this->option('activations') || $activationsOnly) {
            $activationQuery = CouponBeneficiary::query()
                ->where('status', 'assigned')
                ->whereNotNull('activated_at')
                ->whereNull('activation_notified_at')
                ->whereNotNull('user_id')
                ->whereNotNull('child_coupon_id')
                ->when($emailFilter, fn ($q) => $q->where(
                    'email_normalized',
                    CouponBeneficiary::normalizeEmail((string) $emailFilter)
                ))
                ->with(['user', 'childCoupon'])
                ->orderBy('id')
                ->limit($limit);

            foreach ($activationQuery->get() as $beneficiary) {
                if ($beneficiary->user === null || $beneficiary->childCoupon === null) {
                    continue;
                }

                if ($dryRun) {
                    $activationsSent++;
                    $this->line("[DRY-RUN] Notificaría activación a {$beneficiary->email}");
                    continue;
                }

                $before = $beneficiary->activation_notified_at;
                $beneficiaryService->notifyBeneficiaryActivated(
                    $beneficiary,
                    $beneficiary->user,
                    $beneficiary->childCoupon
                );
                if ($beneficiary->fresh()->activation_notified_at !== null && $before === null) {
                    $activationsSent++;
                }
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info($prefix.'Invitaciones enviadas: '.$invitationsSent);
        $this->info($prefix.'Invitaciones omitidas: '.$invitationSkipped);
        if ($this->option('activations') || $activationsOnly) {
            $this->info($prefix.'Activaciones notificadas: '.$activationsSent);
        }
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
