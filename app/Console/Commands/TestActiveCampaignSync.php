<?php

namespace App\Console\Commands;

use App\Jobs\SyncUserToActiveCampaign;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestActiveCampaignSync extends Command
{
    protected $signature = 'activecampaign:test-sync {email?}';
    protected $description = 'Test ActiveCampaign sync for a user';

    public function handle()
    {
        $email = $this->argument('email');
        
        if ($email) {
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                $this->error("User with email {$email} not found");
                return 1;
            }
        } else {
            $user = User::latest()->first();
            
            if (!$user) {
                $this->error("No users found in database");
                return 1;
            }
            
            $this->info("Using latest user: {$user->email}");
        }
        
        $this->info("Testing ActiveCampaign sync for user:");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Email', $user->email],
                ['Name', $user->name],
                ['Phone', $user->phone],
                ['Created', $user->created_at],
            ]
        );
        
        if ($this->confirm('Do you want to sync this user to ActiveCampaign?')) {
            $this->info("Dispatching sync job...");
            
            SyncUserToActiveCampaign::dispatchSync($user);
            
            $this->info("Job dispatched. Check logs for details.");
            $this->info("Log file: " . storage_path('logs/laravel.log'));
        }
        
        return 0;
    }
}