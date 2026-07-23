<?php

namespace App\Console\Commands;

use App\Models\OtpAbuseEvent;
use Illuminate\Console\Command;

/**
 * Purge old otp_abuse_events past retention_days.
 * NOT scheduled in production yet (P0-A3) — run manually when needed.
 */
class PurgeOtpAbuseEventsCommand extends Command
{
    protected $signature = 'otp:purge-abuse-events {--days= : Override otp.p0a.anti_abuse.retention_days}';

    protected $description = 'Elimina eventos antiabuso OTP mas antiguos que el horizonte de retencion';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('otp.p0a.anti_abuse.retention_days', 30));
        $days = max(1, $days);

        $cutoff = now()->subDays($days);

        $deleted = OtpAbuseEvent::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Purgados {$deleted} eventos otp_abuse_events anteriores a {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
