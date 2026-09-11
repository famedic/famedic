<?php

namespace App\Console\Commands;

use App\Services\Odessa\Reconciliation\OdessaReconciliationService;
use Illuminate\Console\Command;

class ReconcileOdessaCollaboratorsCommand extends Command
{
    protected $signature = 'odessa:reconcile-collaborators
        {path : Ruta al Excel de colaboradores ODESSA/FAMEDIC}
        {--murguia= : Ruta opcional al reporte Murguía}
        {--output= : Ruta relativa en storage/app para el XLSX de salida}';

    protected $description = 'Concilia colaboradores ODESSA contra usuarios, customers, cuentas ODESSA y membresías FAMEDIC sin modificar datos.';

    public function handle(OdessaReconciliationService $service): int
    {
        try {
            $report = $service->reconcile(
                (string) $this->argument('path'),
                $this->option('murguia') ? (string) $this->option('murguia') : null,
                $this->option('output') ? (string) $this->option('output') : null,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('ODESSA Reconciliation');
        $this->line('=====================');
        $this->line('');
        $this->line('Archivo: '.$report->sourcePath);
        $this->line('');
        $this->line('Filas procesadas: '.$report->summary->total);
        $this->line('Personas únicas: '.$report->summary->uniqueTotal);
        $this->line('Duplicados Excel: '.$report->summary->duplicates);
        $this->line('');

        $this->line('MATCH');
        $this->line('-----');
        foreach ($report->summary->matchTypes as $matchType => $count) {
            $this->line(str_pad($matchType.':', 45).$count);
        }

        $this->line('');
        $this->line('RESULTADO');
        $this->line('---------');
        foreach ($report->summary->statuses as $status => $count) {
            $this->line(str_pad($status.':', 45).$count);
        }

        $this->line('');
        $this->line('Membresías');
        $this->line('-----------');
        $this->line(str_pad('Con número:', 45).$report->summary->withMembershipNumber);
        $this->line(str_pad('Activas:', 45).$report->summary->withActiveMembership);
        $this->line(str_pad('Vencidas/inactivas:', 45).$report->summary->withExpiredMembership);
        $this->line(str_pad('Faltantes:', 45).$report->summary->withoutMembership);

        $this->line('');
        $this->line(str_pad('Recuperados vs email-only:', 45).$report->summary->emailOnlyWouldHaveMissed);
        $this->line('');
        $this->info('XLSX generado: '.$report->exportPath);

        return self::SUCCESS;
    }
}
