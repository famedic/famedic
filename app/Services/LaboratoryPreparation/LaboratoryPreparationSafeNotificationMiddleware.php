<?php

namespace App\Services\LaboratoryPreparation;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keep queued summary transport failures within the preparation P2 boundary. */
final class LaboratoryPreparationSafeNotificationMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $exception) {
            $safe = $exception instanceof LaboratoryPreparationOperationalException
                ? $exception : new LaboratoryPreparationOperationalException('notification');
            Log::error('laboratory_preparation_summary_notification_job_failed', $safe->context());

            throw $safe;
        }
    }
}
