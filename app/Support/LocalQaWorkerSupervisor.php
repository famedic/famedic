<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/** Owns only the two child processes it starts. Never signals other workers. */
class LocalQaWorkerSupervisor
{
    public function run(array $commands, callable $output, callable $stopping): int
    {
        $children = [];
        try {
            foreach ($commands as $kind => $command) {
                $process = new Process($command, base_path(), null, null, null);
                $process->start(fn ($type, $buffer) => $output($kind, $buffer));
                $children[$kind] = $process;
            }
            while (! $stopping()) {
                foreach ($children as $kind => $process) {
                    if (! $process->isRunning()) {
                        $output($kind, "Worker terminado; se detienen ambos. Revisa la salida antes de comprar.\n");
                        return 1;
                    }
                }
                usleep(100000);
            }
            return 0;
        } finally {
            // Signal both first, then let in-flight jobs finish within their existing timeouts.
            foreach ($children as $process) {
                if ($process->isRunning()) {
                    try { $process->signal(SIGTERM); }
                    catch (\Symfony\Component\Process\Exception\RuntimeException) {
                        // It may have exited between isRunning() and signal(); continue cleanup.
                    }
                }
            }
            $until = microtime(true) + 125;
            do {
                $running = false;
                foreach ($children as $process) { $running = $process->isRunning() || $running; }
                if ($running) { usleep(100000); }
            } while ($running && microtime(true) < $until);
            foreach ($children as $kind => $process) {
                if ($process->isRunning()) {
                    $output($kind, "Parada forzada: revisar el ledger antes de reintentar entregas.\n");
                    $process->stop(0);
                }
            }
        }
    }
}
