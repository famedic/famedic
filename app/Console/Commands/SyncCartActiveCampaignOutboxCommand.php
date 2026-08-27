<?php

namespace App\Console\Commands;

use App\Enums\CartEventType;
use App\Models\CartEvent;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Illuminate\Console\Command;

class SyncCartActiveCampaignOutboxCommand extends Command
{
    protected $signature = 'activecampaign:sync-cart-outbox';

    protected $description = 'Encola dispatches de ActiveCampaign faltantes para eventos de ciclo de vida de carrito.';

    /** @var list<string> */
    private const RECONCILABLE_EVENTS = [
        CartEventType::CartAbandoned->value,
        CartEventType::CartResumed->value,
        CartEventType::CartRecovered->value,
    ];

    public function __construct(
        private ActiveCampaignOutboundDispatcher $outboundDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->outboundDispatcher->isCartOutboxEnabled()) {
            $this->info('Cart outbox desactivado (ACTIVECAMPAIGN_CART_OUTBOX_ENABLED=false).');

            return self::SUCCESS;
        }

        $queued = 0;

        CartEvent::query()
            ->whereIn('event', self::RECONCILABLE_EVENTS)
            ->with(['cart.user.customer', 'cart.items'])
            ->orderBy('id')
            ->chunkById(100, function ($events) use (&$queued) {
                foreach ($events as $event) {
                    $cart = $event->cart;

                    if ($cart === null) {
                        continue;
                    }

                    foreach ($this->outboundDispatcher->enqueueFromCartEvent($cart, $event) as $dispatch) {
                        if ($dispatch->wasRecentlyCreated) {
                            $queued++;
                        }
                    }
                }
            });

        $this->info("Cart outbox sync finished. New dispatches queued: {$queued}.");

        return self::SUCCESS;
    }
}
