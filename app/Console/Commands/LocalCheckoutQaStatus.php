<?php

namespace App\Console\Commands;

use App\Support\LocalQaPreflight;
use Illuminate\Console\Command;

class LocalCheckoutQaStatus extends Command
{
    protected $signature = 'local-qa:status {--initial} {--ready}';
    protected $description = 'Read-only local checkout QA preflight (no recipient or credentials printed)';
    public function handle(): int
    {
        if (! app()->environment('local')) { $this->error('local_only'); return self::FAILURE; }
        $state = LocalQaPreflight::inspect((bool) $this->option('initial'), (bool) $this->option('ready'));
        $this->line(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $state['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
