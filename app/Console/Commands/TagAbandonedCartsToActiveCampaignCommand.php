<?php

namespace App\Console\Commands;

use App\Jobs\SendCartAbandonedToActiveCampaignJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TagAbandonedCartsToActiveCampaignCommand extends Command
{
    protected $signature = 'activecampaign:tag-abandoned-carts {--minutes= : Minutos de inactividad para considerar "abandonado"}';

    protected $description = 'Detecta carritos abandonados (laboratorio/farmacia) y envía tag a ActiveCampaign.';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('services.activecampaign.cart_abandoned_minutes', 60));
        $minutes = max(10, $minutes);

        $cutoff = now()->subMinutes($minutes);

        Log::info('AC: Comando tag-abandoned-carts iniciado', [
            'minutes' => $minutes,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        // Última actividad del carrito por customer (lab + farmacia) usando UNION ALL.
        $cartActivity = DB::query()
            ->fromSub(function ($q) {
                $q->select('customer_id', 'created_at')
                    ->from('laboratory_cart_items')
                    ->whereNull('deleted_at')
                    ->unionAll(
                        DB::table('online_pharmacy_cart_items')
                            ->select('customer_id', 'created_at')
                            ->whereNull('deleted_at')
                    );
            }, 'cart_items')
            ->selectRaw('customer_id, MAX(created_at) as last_activity_at')
            ->groupBy('customer_id');

        $query = DB::table('customers')
            ->joinSub($cartActivity, 'cart_activity', function ($join) {
                $join->on('cart_activity.customer_id', '=', 'customers.id');
            })
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNotNull('users.email')
            ->where('cart_activity.last_activity_at', '<=', $cutoff->toDateTimeString())
            ->where(function ($q) {
                $q->whereNull('customers.cart_abandoned_tagged_at')
                    ->orWhereRaw('customers.cart_abandoned_tagged_at < cart_activity.last_activity_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('laboratory_purchases')
                    ->whereColumn('laboratory_purchases.customer_id', 'customers.id')
                    ->whereRaw('laboratory_purchases.created_at > cart_activity.last_activity_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('online_pharmacy_purchases')
                    ->whereColumn('online_pharmacy_purchases.customer_id', 'customers.id')
                    ->whereRaw('online_pharmacy_purchases.created_at > cart_activity.last_activity_at');
            })
            ->select([
                'customers.id as customer_id',
                'users.email as email',
                'cart_activity.last_activity_at as last_activity_at',
            ])
            ->orderBy('customers.id');

        $dispatched = 0;

        $query->chunk(200, function ($rows) use (&$dispatched) {
            $now = now()->toDateTimeString();

            foreach ($rows as $row) {
                SendCartAbandonedToActiveCampaignJob::dispatch($row->email);

                DB::table('customers')
                    ->where('id', $row->customer_id)
                    ->update(['cart_abandoned_tagged_at' => $now]);

                $dispatched++;
            }
        });

        Log::info('AC: Comando tag-abandoned-carts completado', [
            'dispatched' => $dispatched,
        ]);

        $this->info("Jobs despachados: {$dispatched}");

        return self::SUCCESS;
    }
}

