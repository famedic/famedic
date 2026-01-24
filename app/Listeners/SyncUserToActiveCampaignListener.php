<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Jobs\SyncUserToActiveCampaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncUserToActiveCampaignListener implements ShouldQueue
{
    public $queue = 'activecampaign';
    
    public function __construct()
    {
        //
    }

    public function handle(UserRegistered $event): void
    {
        if (!config('activecampaign.sync.enabled')) {
            Log::debug('ActiveCampaign sync disabled, skipping listener');
            return;
        }
        
        Log::info('Dispatching ActiveCampaign sync job for new user', [
            'user_id' => $event->user->id,
            'email' => $event->user->email,
        ]);
        
        Log::info('SyncUserToActiveCampaignListener: handling event', [
        'user_id' => $event->user->id,
        'user_data' => [
            'email' => $event->user->email,
            'first_name' => $event->user->name,
            'last_name' => trim(($event->user->paternal_lastname ?? '') . ' ' . ($event->user->maternal_lastname ?? '')),
            'phone' => $event->user->phone,
        ],
        'metadata' => $event->metadata,
    ]);

        SyncUserToActiveCampaign::dispatch($event->user, $event->metadata)
            ->onQueue('activecampaign');
    }
    
    public function shouldQueue(UserRegistered $event): bool
    {
        return config('activecampaign.sync.use_queue', true);
    }
}