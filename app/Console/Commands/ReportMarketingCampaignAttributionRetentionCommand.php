<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaignAttribution;
use Illuminate\Console\Command;

class ReportMarketingCampaignAttributionRetentionCommand extends Command
{
    protected $signature = 'marketing-campaigns:attribution-retention-report {--expired : Incluir sólo expiradas}';

    protected $description = 'Informa conteos de atribuciones para planificar retención (sin borrar datos).';

    public function handle(): int
    {
        $query = MarketingCampaignAttribution::query();

        if ($this->option('expired')) {
            $query->where('expires_at', '<=', now());
        }

        $total = (clone $query)->count();
        $expired = MarketingCampaignAttribution::query()->where('expires_at', '<=', now())->count();
        $active = MarketingCampaignAttribution::query()->where('expires_at', '>', now())->count();

        $this->info("Atribuciones activas: {$active}");
        $this->info("Atribuciones expiradas (historial): {$expired}");
        $this->line("Filtro actual: {$total}");

        $this->comment('Política de retención final pendiente de aprobación; este comando no elimina registros.');

        return self::SUCCESS;
    }
}
