<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignWebActivity;
use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ActiveCampaignWebActivitySyncService
{
    public function __construct(
        protected ActiveCampaignTrackingLogMapper $mapper,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $activities
     */
    public function syncForCustomer(Customer $customer, int $acContactId, array $activities): void
    {
        if (! Schema::hasTable('activecampaign_web_activities')) {
            return;
        }

        foreach ($activities as $activity) {
            $mapped = $this->mapper->fromActivity($activity);
            if ($mapped === null) {
                continue;
            }

            $hash = $this->activityHash((int) $customer->id, $mapped);

            ActiveCampaignWebActivity::query()->updateOrCreate(
                ['activity_hash' => $hash],
                [
                    'customer_id' => (int) $customer->id,
                    'ac_contact_id' => $acContactId,
                    'path' => $mapped['path'],
                    'title' => $mapped['title'],
                    'label' => $mapped['label'],
                    'occurred_at' => $mapped['occurred_at'],
                    'source' => $mapped['source'],
                    'raw_reference_type' => $mapped['raw_reference_type'],
                    'raw_reference_id' => $mapped['raw_reference_id'],
                ],
            );
        }

        $this->prune($customer);
    }

    /**
     * @return Collection<int, ActiveCampaignWebActivity>
     */
    public function forCart(Cart $cart, int $limit = 10): Collection
    {
        $customer = $cart->user?->customer;
        if (! $customer || ! Schema::hasTable('activecampaign_web_activities')) {
            return collect();
        }

        [$from, $to] = $this->cartWindow($cart);

        return ActiveCampaignWebActivity::query()
            ->where('customer_id', $customer->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface}
     */
    public function cartWindow(Cart $cart): array
    {
        $from = ($cart->created_at ?? now())->copy()->subMinutes(10);
        $to = ($cart->completed_at ?? $cart->updated_at ?? now())->copy()->addMinutes(30);

        return [$from, $to];
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function activityHash(int $customerId, array $mapped): string
    {
        return hash('sha256', implode('|', [
            $customerId,
            $mapped['occurred_at']->toIso8601String(),
            $mapped['path'],
            $mapped['raw_reference_id'] ?? '',
        ]));
    }

    private function prune(Customer $customer): void
    {
        ActiveCampaignWebActivity::query()
            ->where('customer_id', $customer->id)
            ->where('occurred_at', '<', now()->subDays(60))
            ->delete();

        $idsToKeep = ActiveCampaignWebActivity::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->pluck('id');

        if ($idsToKeep->isEmpty()) {
            return;
        }

        ActiveCampaignWebActivity::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }
}
