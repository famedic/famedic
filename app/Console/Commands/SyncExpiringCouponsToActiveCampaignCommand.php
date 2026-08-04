<?php

namespace App\Console\Commands;

use App\Models\ActiveCampaignDispatch;
use App\Models\CouponUser;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\CouponActiveCampaignDispatcher;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use App\Services\ActiveCampaign\ExpiringCouponCandidateQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncExpiringCouponsToActiveCampaignCommand extends Command
{
    protected $signature = 'activecampaign:sync-expiring-coupons
                            {--days= : Días antes del vencimiento (default: config)}
                            {--dry-run : Solo reportar conteos y ejemplos sin crear dispatches}
                            {--limit=500 : Máximo de dispatches a encolar}
                            {--force : Ejecutar aunque ACTIVE_CAMPAIGN_COUPONS_EXPIRING_ENABLED=false}';

    protected $description = 'Detecta créditos/cupones próximos a vencer y sincroniza tag FM-Credito-Por-Vencer en ActiveCampaign.';

    public function handle(
        ActiveCampaignDispatchService $dispatchService,
        ExpiringCouponCandidateQuery $candidateQuery,
        CouponActiveCampaignDispatcher $dispatcher,
        CouponActiveCampaignPayloadBuilder $payloadBuilder,
    ): int {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $days = $this->resolveDays();

        if (! $dispatchService->isEnabled()) {
            $this->info('ActiveCampaign desactivado (ACTIVE_CAMPAIGN_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $dispatchService->isCouponsEnabled()) {
            $this->info('Integración cupones AC desactivada (ACTIVE_CAMPAIGN_COUPONS_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $force && ! $dispatchService->isCouponsExpiringEnabled()) {
            $this->info('Sync por vencer desactivado (ACTIVE_CAMPAIGN_COUPONS_EXPIRING_ENABLED=false). Usa --force para omitir.');

            return self::SUCCESS;
        }

        $stats = [
            'candidates' => 0,
            'eligible' => 0,
            'skipped_used' => 0,
            'skipped_expired' => 0,
            'skipped_no_email' => 0,
            'skipped_no_remaining' => 0,
            'skipped_other' => 0,
            'dispatches_created' => 0,
            'dispatches_duplicate' => 0,
        ];

        $examples = [];

        $query = $candidateQuery->candidatesWithinDays($days);
        $stats['candidates'] = (clone $query)->count();

        $this->info(sprintf(
            'Buscando cupones que vencen en los próximos %d días%s...',
            $days,
            $dryRun ? ' (dry-run)' : ''
        ));

        $dispatched = 0;
        $shouldStop = false;

        $query->chunkById(100, function ($assignments) use (
            &$stats,
            &$examples,
            &$dispatched,
            &$shouldStop,
            $candidateQuery,
            $dispatcher,
            $payloadBuilder,
            $dryRun,
            $limit,
            $force,
        ) {
            /** @var CouponUser $assignment */
            foreach ($assignments as $assignment) {
                if ($stats['dispatches_created'] >= $limit || ($dryRun && $dispatched >= $limit)) {
                    $shouldStop = true;
                    break;
                }

                $skipReason = $candidateQuery->skipReason($assignment);

                if ($skipReason !== null) {
                    $this->incrementSkipStat($stats, $skipReason);

                    continue;
                }

                $stats['eligible']++;

                $user = $assignment->user;
                $email = (string) ($user?->email ?? '');

                if ($dryRun) {
                    if (count($examples) < 5) {
                        $examples[] = [
                            'coupon_user_id' => $assignment->id,
                            'email' => $email,
                            'expires_at' => $assignment->coupon?->expires_at?->toDateTimeString(),
                        ];
                    }

                    $dispatched++;

                    continue;
                }

                $idempotencyKey = $payloadBuilder->idempotencyKeyForExpiring((int) $assignment->id);

                $hadExisting = ActiveCampaignDispatch::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                $created = $dispatcher->dispatchCreditExpiring($assignment, $force);

                if ($created) {
                    $stats['dispatches_created']++;
                    $dispatched++;
                } elseif ($hadExisting) {
                    $stats['dispatches_duplicate']++;
                }
            }

            if ($shouldStop) {
                return false;
            }

            return true;
        }, column: 'id');

        Log::info('AC: sync-expiring-coupons completado', array_merge($stats, [
            'days' => $days,
            'dry_run' => $dryRun,
            'force' => $force,
            'limit' => $limit,
        ]));

        $this->table(
            ['Métrica', 'Valor'],
            collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all()
        );

        if ($dryRun && $examples !== []) {
            $this->newLine();
            $this->info('Ejemplos elegibles (dry-run):');
            $this->table(['coupon_user_id', 'email', 'expires_at'], $examples);
        }

        return self::SUCCESS;
    }

    private function resolveDays(): int
    {
        $option = $this->option('days');

        if ($option !== null && $option !== '') {
            return max(1, (int) $option);
        }

        return max(1, (int) config('services.activecampaign.coupons_expiring_days', 14));
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function incrementSkipStat(array &$stats, string $reason): void
    {
        match ($reason) {
            'used', 'consumed' => $stats['skipped_used']++,
            'expired' => $stats['skipped_expired']++,
            'no_email' => $stats['skipped_no_email']++,
            'no_remaining' => $stats['skipped_no_remaining']++,
            default => $stats['skipped_other']++,
        };
    }
}
