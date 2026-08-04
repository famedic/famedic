<?php

namespace App\Console\Commands;

use App\Services\TaxProfiles\InvoiceRequestTaxProfileLinker;
use Illuminate\Console\Command;

/**
 * PF-1B.1: solo auditoría. No escribe tax_profile_id.
 * El apply queda fuera del comando hasta una fase que autorice escritura operativa.
 */
class AuditInvoiceRequestTaxProfileLinksCommand extends Command
{
    protected $signature = 'tax-profiles:audit-invoice-request-links
                            {--limit= : Máximo de solicitudes SIN tax_profile_id a inspeccionar (muestra parcial)}';

    protected $description = 'Audita invoice_requests históricas sin tax_profile_id (solo lectura)';

    public function handle(InvoiceRequestTaxProfileLinker $linker): int
    {
        $limitOption = $this->option('limit');
        $limit = filled($limitOption) ? max(0, (int) $limitOption) : null;

        if ($limit === 0) {
            $this->warn('--limit=0 no inspecciona filas.');

            return self::SUCCESS;
        }

        $rows = $linker->auditUnlinked($limit);

        $counts = [
            InvoiceRequestTaxProfileLinker::CLASS_UNIQUE => 0,
            InvoiceRequestTaxProfileLinker::CLASS_AMBIGUOUS => 0,
            InvoiceRequestTaxProfileLinker::CLASS_NONE => 0,
            InvoiceRequestTaxProfileLinker::CLASS_UNRESOLVED_OWNER => 0,
        ];

        foreach ($rows as $row) {
            if (array_key_exists($row['classification'], $counts)) {
                $counts[$row['classification']]++;
            }
        }

        $this->info('Solicitudes sin tax_profile_id inspeccionadas: '.$rows->count());
        if ($limit !== null) {
            $this->comment("Nota: --limit={$limit} produce una muestra parcial; los conteos no son el universo completo.");
        }

        $this->table(
            ['Clasificación', 'Cantidad'],
            collect($counts)->map(fn ($count, $class) => [$class, $count])->values()->all()
        );

        $ambiguous = $rows->where('classification', InvoiceRequestTaxProfileLinker::CLASS_AMBIGUOUS);
        if ($ambiguous->isNotEmpty()) {
            $this->warn('Coincidencias ambiguas (IDs operativos únicamente; sin datos fiscales):');
            foreach ($ambiguous->take(50) as $row) {
                $this->line(sprintf(
                    '  invoice_request_id=%d customer_id=%s candidate_ids=[%s]',
                    $row['invoice_request_id'],
                    $row['customer_id'] ?? 'null',
                    implode(',', $row['candidate_ids'])
                ));
            }
        }

        $this->comment('Modo auditoría (sin escritura). PF-1B.1 no aplica vínculos desde este comando.');

        return self::SUCCESS;
    }
}
