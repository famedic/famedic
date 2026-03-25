<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCartAbandonedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $email;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        if (! config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            return;
        }

        Log::info('AC: Job SendCartAbandonedToActiveCampaignJob iniciado', [
            'email' => $this->email,
        ]);

        try {
            $user = User::query()->where('email', $this->email)->first();

            if (! $user) {
                Log::warning('AC: cartAbandoned omitido — usuario no encontrado en DB', [
                    'email' => $this->email,
                ]);
                return;
            }

            // Asegura que el contacto exista en AC antes de tagear.
            $activeCampaignService->syncContact([
                'email' => $user->email,
                'first_name' => $user->name,
                'paternal_lastname' => $user->paternal_lastname,
                'maternal_lastname' => $user->maternal_lastname,
                'phone' => $user->phone,
                'gender' => $user->gender == 1 ? 'Masculino' : 'Femenino',
                'birth_date' => optional($user->birth_date)?->format('Y-m-d'),
                'phone_country' => $user->phone_country,
                'state' => $user->state,
            ]);

            $activeCampaignService->cartAbandoned($this->email);

            Log::info('AC: Job SendCartAbandonedToActiveCampaignJob completado', [
                'email' => $this->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('AC: Error en SendCartAbandonedToActiveCampaignJob', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

