<?php

namespace App\Console\Commands;

use App\Support\EfevooPayGatewayInspector;
use Illuminate\Console\Command;

class InspectEfevooPayLocalCommand extends Command
{
    protected $signature = 'efevoo:inspect-local';

    protected $description = 'Comprueba configuracion local de EfevooPay sin llamadas externas';

    public function handle(): int
    {
        $report = EfevooPayGatewayInspector::inspect();

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
