<?php

namespace App\Console\Commands;

use App\Services\Carts\AppointmentPendingDetectionService;
use Illuminate\Console\Command;

class DetectAppointmentPendingCommand extends Command
{
    protected $signature = 'carts:detect-appointment-pending';

    protected $description = 'Detecta citas sin confirmar >= umbral y registra appointment_pending_5m.';

    public function __construct(
        private AppointmentPendingDetectionService $detectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $recorded = 0;

        foreach ($this->detectionService->appointmentsEligibleForDetection() as $appointment) {
            $event = $this->detectionService->detectAndRecord($appointment);

            if ($event !== null) {
                $recorded++;
            }
        }

        $this->info("Appointment pending detection finished. Recorded {$recorded} appointment_pending_5m event(s).");

        return self::SUCCESS;
    }
}
