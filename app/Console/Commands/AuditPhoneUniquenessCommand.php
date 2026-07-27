<?php

namespace App\Console\Commands;

use App\Services\Otp\Registration\PhoneUniquenessAuditor;
use Illuminate\Console\Command;

/**
 * Non-destructive phone uniqueness audit (P0-A5.7A). Counts only — no PII.
 */
class AuditPhoneUniquenessCommand extends Command
{
    protected $signature = 'akubica:audit-phone-uniqueness';

    protected $description = 'Report users.phone uniqueness readiness (counts only; no PII)';

    public function handle(PhoneUniquenessAuditor $auditor): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Refusing to run in production without explicit ops process.');

            return self::FAILURE;
        }

        $report = $auditor->audit();

        foreach ($report as $key => $value) {
            $this->line($key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }

        if ($report['blocks_unique_index']) {
            $this->warn('Duplicate (phone_country, phone) groups block UNIQUE DDL. Remediación manual D2 requerida.');

            return self::FAILURE;
        }

        $this->info('No literal duplicate groups; UNIQUE(phone_country, phone) preflight OK.');

        return self::SUCCESS;
    }
}
