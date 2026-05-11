<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Console\Command;

class SyncMonitoringCartsCommand extends Command
{
    protected $signature = 'monitoring:sync-carts {--chunk=100}';

    protected $description = 'Sincroniza la tabla carts desde laboratory_cart_items y online_pharmacy_cart_items';

    public function handle(SyncMonitoringCartService $sync): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        Customer::query()
            ->where(function ($q) {
                $q->whereHas('laboratoryCartItems')
                    ->orWhereHas('onlinePharmacyCartItems');
            })
            ->chunkById($chunk, function ($customers) use ($sync) {
                foreach ($customers as $customer) {
                    $sync->syncLaboratory($customer);
                    $sync->syncPharmacy($customer);
                }
            });

        $this->info('Sincronización de carritos de monitoreo completada.');

        return self::SUCCESS;
    }
}
