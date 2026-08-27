<?php

namespace App\Console\Commands;

use App\Services\Carts\CartAbandonmentService;
use Illuminate\Console\Command;

class DetectCartAbandonmentCommand extends Command
{
    protected $signature = 'carts:detect-abandonment';

    protected $description = 'Detecta carritos activos inactivos y registra cart_abandoned en cart_events.';

    public function __construct(
        private CartAbandonmentService $cartAbandonmentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $recorded = 0;

        foreach ($this->cartAbandonmentService->cartsEligibleForAbandonmentDetection() as $cart) {
            $event = $this->cartAbandonmentService->recordAbandoned($cart);

            if ($event !== null) {
                $recorded++;
            }
        }

        $this->info("Cart abandonment detection finished. Recorded {$recorded} cart_abandoned event(s).");

        return self::SUCCESS;
    }
}
