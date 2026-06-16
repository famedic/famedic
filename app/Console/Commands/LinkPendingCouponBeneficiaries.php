<?php

namespace App\Console\Commands;

use App\Models\CouponBeneficiary;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use Illuminate\Console\Command;

class LinkPendingCouponBeneficiaries extends Command
{
    protected $signature = 'coupons:link-pending-beneficiaries
                            {--dry-run : Simula la vinculación sin modificar datos}
                            {--email= : Procesar solo usuarios con este correo}
                            {--limit=100 : Máximo de usuarios a revisar}';

    protected $description = 'Vincula beneficiarios pending_user con usuarios que ya verificaron su email.';

    public function handle(CouponBeneficiaryService $beneficiaryService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $emailFilter = $this->option('email');
        $limit = max(1, (int) $this->option('limit'));

        $normalizedPendingEmails = CouponBeneficiary::query()
            ->where('status', 'pending_user')
            ->whereNull('child_coupon_id')
            ->whereNull('cancelled_at')
            ->when($emailFilter, fn ($q) => $q->where('email_normalized', CouponBeneficiary::normalizeEmail((string) $emailFilter)))
            ->distinct()
            ->pluck('email_normalized');

        if ($normalizedPendingEmails->isEmpty()) {
            $this->info('No hay beneficiarios pendientes para vincular.');

            return self::SUCCESS;
        }

        $usersQuery = User::query()
            ->whereNotNull('email_verified_at')
            ->where(function ($q) use ($normalizedPendingEmails) {
                $placeholders = implode(',', array_fill(0, $normalizedPendingEmails->count(), '?'));
                $q->whereRaw('LOWER(TRIM(email)) IN ('.$placeholders.')', $normalizedPendingEmails->all());
            })
            ->when($emailFilter, fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [CouponBeneficiary::normalizeEmail((string) $emailFilter)]))
            ->orderBy('id')
            ->limit($limit);

        $users = $usersQuery->get();

        $usersReviewed = 0;
        $totalLinked = 0;
        $totalSkipped = 0;
        $allErrors = [];

        foreach ($users as $user) {
            $usersReviewed++;
            $result = $beneficiaryService->linkPendingBeneficiariesForUser($user, $dryRun);

            $totalLinked += $result['linked_count'];
            $totalSkipped += $result['skipped_count'];
            $allErrors = array_merge($allErrors, $result['errors']);

            if ($result['linked_count'] > 0 || $result['skipped_count'] > 0) {
                $this->line(sprintf(
                    'Usuario #%d (%s): vinculados=%d, omitidos=%d',
                    $user->id,
                    $user->email,
                    $result['linked_count'],
                    $result['skipped_count'],
                ));
            }
        }

        $mode = $dryRun ? '[DRY-RUN] ' : '';
        $this->info($mode.'Usuarios revisados: '.$usersReviewed);
        $this->info($mode.'Beneficiarios vinculados: '.$totalLinked);
        $this->info($mode.'Beneficiarios omitidos: '.$totalSkipped);

        if ($allErrors !== []) {
            $this->warn('Errores: '.count($allErrors));
            foreach ($allErrors as $error) {
                $this->error('  - '.$error);
            }
        }

        return self::SUCCESS;
    }
}
