<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUserToActiveCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    
    public $backoff = [60, 300, 600]; // Retry after 1, 5, 10 minutes
    public $queue = 'activecampaign';
    
    public function __construct(
        protected User $user,
        protected array $metadata = []
    ) {}

    public function handle(ActiveCampaignService $service): void
    {
        if (!config('activecampaign.sync.enabled')) {
            Log::info('ActiveCampaign sync disabled, skipping', ['user_id' => $this->user->id]);
            return;
        }

        Log::info('Syncing user to ActiveCampaign', [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'queue' => $this->queue,
        ]);

        try {
            // Usar el método prepareUserData del servicio para obtener todos los datos, incluidos campos personalizados
            $userData = $service->prepareUserData($this->user);
            
            // Extraer campos personalizados si existen
            $customFields = $userData['custom_fields'] ?? [];
            
            // Get list ID and tags
            $listId = config('activecampaign.lists.default', 5);
            $tags = [config('activecampaign.tags.registro_nuevo', 'RegistroNuevo')];
            
            // Add additional tags from metadata
            if (isset($this->metadata['tags']) && is_array($this->metadata['tags'])) {
                $tags = array_merge($tags, $this->metadata['tags']);
            }
            
            // Preparar datos básicos para el contacto
            $contactData = [
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'phone' => $userData['phone'] ?? null,
            ];
            
            // Sync to ActiveCampaign CON campos personalizados
            $result = $service->syncContactWithCustomFields($contactData, $listId, $tags, $customFields);
            
            if ($result['success']) {
                Log::info('User synced to ActiveCampaign successfully', [
                    'user_id' => $this->user->id,
                    'contact_id' => $result['contact_id'],
                    'action' => $result['action'],
                ]);
            } else {
                Log::error('Failed to sync user to ActiveCampaign', [
                    'user_id' => $this->user->id,
                    'error' => $result['error'],
                ]);
                
                // Retry if not a permanent error
                if (!$this->isPermanentError($result['error'])) {
                    $this->release(60); // Retry after 1 minute
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Exception syncing user to ActiveCampaign', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Don't retry on certain errors
            if ($this->isPermanentError($e->getMessage())) {
                $this->fail($e);
            }
        }
    }
    
    /**
     * Determine if error is permanent (no point in retrying)
     */
    private function isPermanentError(string $error): bool
    {
        $permanentErrors = [
            'Invalid API token',
            'Insufficient permissions',
            'Resource not found',
        ];
        
        foreach ($permanentErrors as $permanentError) {
            if (str_contains($error, $permanentError)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function failed(\Throwable $exception): void
    {
        Log::critical('SyncUserToActiveCampaign job failed permanently', [
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
            'job' => get_class($this),
        ]);
    }
}