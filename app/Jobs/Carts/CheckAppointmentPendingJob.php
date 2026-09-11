<?php

namespace App\Jobs\Carts;

use App\Models\LaboratoryAppointment;
use App\Services\Carts\AppointmentPendingDetectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckAppointmentPendingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $appointmentId,
    ) {}

    public function handle(AppointmentPendingDetectionService $detectionService): void
    {
        $appointment = LaboratoryAppointment::query()
            ->with(['cart.items', 'cart.user.customer'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $appointment->refresh();
        $detectionService->detectAndRecord($appointment);
    }
}
