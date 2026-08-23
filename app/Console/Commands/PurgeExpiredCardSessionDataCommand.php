<?php

namespace App\Console\Commands;

use App\Support\DatabaseSessionSensitiveCardDataPurger;
use Illuminate\Console\Command;

class PurgeExpiredCardSessionDataCommand extends Command
{
    protected $signature = 'efevoopay:purge-expired-card-session-data
                            {--apply : Persist purges instead of dry-run}
                            {--session-id= : Target a single Laravel session id (diagnostic, no payload output)}
                            {--batch= : Batch size override}';

    protected $description = 'Purge expired 3ds_card_data_* keys from Laravel database session storage (dry-run by default).';

    public function handle(DatabaseSessionSensitiveCardDataPurger $purger): int
    {
        $apply = (bool) $this->option('apply');
        $driver = $purger->sessionDriver();

        if (! $purger->supportsCurrentDriver()) {
            $this->error('Session driver "'.$driver.'" is not supported. Only "'.DatabaseSessionSensitiveCardDataPurger::SUPPORTED_DRIVER.'" is supported.');
            $this->line('Redis, file, cookie and array sessions are not purged by this command.');
            $this->line('Configure SESSION_DRIVER=database for automated GC, or implement a driver-specific strategy.');

            return self::FAILURE;
        }

        $stats = $purger->run(
            $apply,
            $this->option('session-id') ?: null,
            $this->option('batch') ? (int) $this->option('batch') : null
        );

        $this->line('Session driver: '.$driver);
        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line('Candidates scanned: '.$stats['candidates']);
        $this->line('Active within TTL (skipped): '.$stats['active_skipped']);
        $this->line('Expired keys found: '.$stats['expired_found']);
        $this->line('Abandoned candidates: '.$stats['abandoned_candidates']);
        $this->line('Sessions '.($apply ? 'purged' : 'would purge').': '.$stats['purged']);
        $this->line('Stale concurrent updates (skipped): '.$stats['stale_skipped']);
        $this->line('Errors: '.$stats['errors']);

        if (! $apply) {
            $this->comment('No changes written. Re-run with --apply after operational approval.');
        }

        return self::SUCCESS;
    }
}
