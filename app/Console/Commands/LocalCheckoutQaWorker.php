<?php

namespace App\Console\Commands;

use App\Support\LocalCheckoutQa;
use App\Support\LocalQaPreflight;
use Illuminate\Console\Command;

class LocalCheckoutQaWorker extends Command
{
    protected $signature = 'local-qa:worker {kind : confirmations or preparation} {--fingerprint=} {--max-time=900 : Stop after this many seconds; 0 keeps listening}';
    protected $description = 'Start only an exclusive local QA queue after matching the browser configuration';
    public function handle(): int
    {
        $state = LocalQaPreflight::inspect(ready: true);
        if (! $state['ok'] || ! hash_equals(LocalCheckoutQa::fingerprint(), (string) $this->option('fingerprint'))) {
            $this->error('local_qa_preflight_or_fingerprint_failed'); return self::FAILURE;
        }
        $kind = $this->argument('kind');
        if (! in_array($kind, ['confirmations', 'preparation'], true)) { return self::FAILURE; }
        $this->info('QA config: '.LocalCheckoutQa::fingerprint());
        return $this->call('queue:work', ['connection' => 'database', '--queue' => 'laboratory-'.$kind.'-qa',
            '--sleep' => 1, '--timeout' => $kind === 'confirmations' ? 60 : 120, '--tries' => 1, '--max-time' => max(0, (int) $this->option('max-time'))]);
    }
}
