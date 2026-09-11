<?php

namespace App\Console\Commands;

use App\Models\LaboratoryAppointment;
use App\Services\Carts\AppointmentCartLinkBackfillApplier;
use App\Services\Carts\AppointmentCartLinkBackfillMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillAppointmentCartLinksCommand extends Command
{
    protected $signature = 'carts:backfill-appointment-cart-links
                            {--dry-run : Mostrar propuestas sin modificar la base (default)}
                            {--apply : Aplicar matches de alta confianza}
                            {--appointment= : Filtrar por laboratory_appointment_id}
                            {--customer= : Filtrar por customer_id}
                            {--cart= : Forzar cart candidato específico}
                            {--limit=100 : Máximo de citas legacy a analizar}
                            {--force-production : Permitir --apply en producción}';

    protected $description = 'Backfill seguro de laboratory_appointments.cart_id y cart_events.appointment_requested para citas legacy';

    public function handle(
        AppointmentCartLinkBackfillMatcher $matcher,
        AppointmentCartLinkBackfillApplier $applier,
    ): int {
        $apply = (bool) $this->option('apply');
        $forcedCartId = $this->optionInt('cart');
        $limit = max(1, (int) ($this->option('limit') ?: 100));

        if ($apply && app()->environment('production') && ! $this->option('force-production')) {
            $this->error('Apply bloqueado en producción. Usa --force-production sólo si estás seguro.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('laboratory_appointments', 'cart_id')) {
            $this->error('Columna laboratory_appointments.cart_id no disponible.');

            return self::FAILURE;
        }

        $this->info($apply ? 'BACKFILL APPOINTMENT CART LINKS — APPLY' : 'BACKFILL APPOINTMENT CART LINKS — DRY RUN');
        $this->line('Environment: '.app()->environment());
        $this->line('Database: '.config('database.default').' / '.config('database.connections.'.config('database.default').'.database'));
        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->newLine();

        $appointments = $this->legacyAppointmentsQuery($limit)->get();
        if ($appointments->isEmpty()) {
            $this->comment('No se encontraron citas legacy sin cart_id para el filtro indicado.');

            return self::SUCCESS;
        }

        $summary = [
            'analyzed' => 0,
            'matched' => 0,
            'already_linked' => 0,
            'no_match' => 0,
            'ambiguous' => 0,
            'applied' => 0,
        ];

        $rows = [];

        foreach ($appointments as $appointment) {
            $assessment = $matcher->assess($appointment, $forcedCartId);
            $summary['analyzed']++;

            match ($assessment['action']) {
                AppointmentCartLinkBackfillMatcher::STATUS_MATCHED => $summary['matched']++,
                AppointmentCartLinkBackfillMatcher::STATUS_ALREADY_LINKED => $summary['already_linked']++,
                AppointmentCartLinkBackfillMatcher::STATUS_AMBIGUOUS => $summary['ambiguous']++,
                default => $summary['no_match']++,
            };

            $actionLabel = $assessment['action'];
            if ($apply && $assessment['action'] === AppointmentCartLinkBackfillMatcher::STATUS_MATCHED) {
                $applied = $applier->apply($assessment);
                if ($applied) {
                    $summary['applied']++;
                    $actionLabel = 'APPLIED';
                } else {
                    $actionLabel = 'APPLY_SKIPPED';
                }
            }

            $rows[] = [
                $assessment['appointment_id'],
                $assessment['customer_id'] ?? '-',
                $assessment['brand'] ?: '-',
                $assessment['appointment_created_at'] ? substr($assessment['appointment_created_at'], 0, 19) : '-',
                $assessment['candidate_cart_id'] ?? '-',
                $assessment['confidence'],
                $assessment['score'] ?? '-',
                mb_substr($assessment['reason'], 0, 60),
                $actionLabel,
            ];
        }

        $this->table(
            ['appointment_id', 'customer_id', 'brand', 'appointment_created_at', 'candidate_cart_id', 'confidence', 'score', 'reason', 'action'],
            $rows,
        );

        $this->newLine();
        $this->info('Resumen');
        $this->line('Analizadas: '.$summary['analyzed']);
        $this->line('Matched: '.$summary['matched']);
        $this->line('Already linked: '.$summary['already_linked']);
        $this->line('Ambiguous: '.$summary['ambiguous']);
        $this->line('No match: '.$summary['no_match']);
        if ($apply) {
            $this->line('Applied: '.$summary['applied']);
        }

        if (! $apply) {
            $this->comment('Dry run: no se modificó la base. Usa --apply para aplicar sólo MATCHED de confianza alta.');
        } else {
            $this->comment('Backfill local únicamente. No se despachó ActiveCampaign.');
        }

        return self::SUCCESS;
    }

    private function legacyAppointmentsQuery(int $limit)
    {
        $query = LaboratoryAppointment::query()->orderByDesc('created_at');

        if ($appointmentId = $this->optionInt('appointment')) {
            $query->where('id', $appointmentId);
        } else {
            $query->whereNull('cart_id');
        }

        if ($customerId = $this->optionInt('customer')) {
            $query->where('customer_id', $customerId);
        }

        return $query->limit($limit);
    }

    private function optionInt(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : max(1, (int) $value);
    }
}
