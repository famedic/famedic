<?php

namespace App\Console\Commands;

use App\Services\Otp\Registration\AkubicaRegistrationIntentService;
use Illuminate\Console\Command;

/**
 * Expire PENDING akubica_registration_intents past expires_at and erase ciphertext.
 * NOT scheduled in production yet (P0-A5.3) — run manually / tests.
 */
class ExpireAkubicaRegistrationIntentsCommand extends Command
{
    protected $signature = 'akubica:expire-registration-intents';

    protected $description = 'Expira intents de registro Akubica vencidos y elimina el ciphertext';

    public function handle(AkubicaRegistrationIntentService $service): int
    {
        $expired = $service->expireDuePending();

        $this->info("Intents de registro expirados en esta ejecucion: {$expired}.");

        return self::SUCCESS;
    }
}
