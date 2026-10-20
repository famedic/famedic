<?php

namespace App\Console\Commands;

use App\Support\LocalQaPreflight;
use App\Support\LocalQaWorkerSupervisor;
use Illuminate\Console\Command;

class ListenLocalCheckoutQa extends Command
{
    protected $signature = 'local-qa:listen {--check : Validate only, without starting workers}';
    protected $description = 'Listen to both local QA queues in one terminal, with automatic configuration verification';

    public function handle(LocalQaWorkerSupervisor $supervisor): int
    {
        $state = LocalQaPreflight::inspect(ready: true);
        if (! $state['ok']) {
            $this->error('No se iniciaron workers: '.implode(', ', $state['issues']));
            return self::FAILURE;
        }
        if (! extension_loaded('pcntl') || ! function_exists('proc_open')) {
            $this->error('Se requieren pcntl y proc_open en el PHP existente.');
            return self::FAILURE;
        }
        // A manually launched worker must be stopped by its owner, never killed here.
        foreach (glob('/proc/[0-9]*/cmdline') as $file) {
            $args = explode("\0", @file_get_contents($file) ?: '');
            if (in_array('artisan', $args, true) || in_array(base_path('artisan'), $args, true)) {
                if (in_array('local-qa:worker', $args, true)) {
                    $this->error('Ya hay un worker QA. Detenlo con Ctrl+C en su terminal antes de usar este comando.');
                    return self::FAILURE;
                }
            }
        }
        $this->info('Configuración válida. Colas: laboratory-confirmations-qa y laboratory-preparation-qa.');
        if ($this->option('check')) { return self::SUCCESS; }
        $lock = fopen(storage_path('framework/local-qa-listen.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            $this->error('Ya existe otro supervisor QA.');
            return self::FAILURE;
        }
        $stopping = false;
        $this->trap([SIGINT, SIGTERM], function () use (&$stopping) {
            if (! $stopping) { $this->info('Deteniendo ambos workers; espera a que termine el trabajo en curso...'); }
            $stopping = true;
        });
        $commands = [];
        foreach (['confirmations', 'preparation'] as $kind) {
            $commands[$kind] = [PHP_BINARY, base_path('artisan'), 'local-qa:worker', $kind,
                '--fingerprint='.$state['fingerprint'], '--max-time=0'];
        }
        $this->info('Ambos workers permanecerán escuchando. Ctrl+C detiene los dos.');
        try {
            return $supervisor->run($commands,
                fn ($kind, $buffer) => $this->output->write('['.$kind.'] '.$buffer),
                function () use (&$stopping) { return $stopping; });
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
