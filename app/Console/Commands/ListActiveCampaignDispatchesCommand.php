<?php

namespace App\Console\Commands;

use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\LaboratoryActiveCampaignDiagnostics;
use Illuminate\Console\Command;

class ListActiveCampaignDispatchesCommand extends Command
{
    protected $signature = 'activecampaign:dispatches
                            {--id= : Mostrar detalle seguro de un dispatch}
                            {--status= : Filtrar por status}
                            {--operation= : Filtrar por payload.operation}
                            {--event-type= : Filtrar por event_type}
                            {--limit=50 : Numero maximo de filas}';

    protected $description = 'Lista dispatches ActiveCampaign con filtros y payload redactado (solo lectura).';

    public function handle(LaboratoryActiveCampaignDiagnostics $diagnostics): int
    {
        $id = $this->option('id');

        if ($id !== null && trim((string) $id) !== '') {
            $dispatch = ActiveCampaignDispatch::query()->find((int) $id);

            if (! $dispatch) {
                $this->error("Dispatch {$id} no encontrado.");

                return self::FAILURE;
            }

            $row = $diagnostics->dispatchRow($dispatch, detail: true);

            foreach ($row as $key => $value) {
                if (is_array($value)) {
                    $this->line($key.': '.json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    continue;
                }

                $this->line($key.': '.($value ?? '-'));
            }

            return self::SUCCESS;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $rows = $diagnostics->dispatchQuery([
            'status' => $this->option('status') ?: null,
            'operation' => $this->option('operation') ?: null,
            'event_type' => $this->option('event-type') ?: null,
        ])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActiveCampaignDispatch $dispatch): array => $diagnostics->dispatchRow($dispatch))
            ->all();

        $this->table(
            ['ID', 'STATUS', 'OPERATION', 'EVENT_TYPE', 'ATTEMPTS', 'CUSTOMER', 'PURCHASE', 'CART', 'CREATED_AT', 'FIELD_KEYS', 'LAST_ERROR'],
            collect($rows)->map(fn (array $row): array => [
                $row['id'],
                $row['status'],
                $row['operation'] ?? '-',
                $row['event_type'],
                $row['attempts'],
                $row['customer_id'] ?? '-',
                $row['purchase_id'] ?? '-',
                $row['cart_id'] ?? '-',
                $row['created_at'] ?? '-',
                $row['field_keys'] ?: '-',
                $row['last_error'] ?? '-',
            ])->all()
        );

        return self::SUCCESS;
    }
}
