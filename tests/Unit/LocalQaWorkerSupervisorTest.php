<?php

namespace Tests\Unit;

use App\Support\LocalQaWorkerSupervisor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalQaWorkerSupervisorTest extends TestCase
{
    #[Test]
    public function ctrl_c_predicate_stops_both_children_and_leaves_no_orphans(): void
    {
        $output = ''; $started = microtime(true);
        $probe = [PHP_BINARY, '-r', 'echo getmypid().PHP_EOL; usleep(5000000);'];
        $result = (new LocalQaWorkerSupervisor)->run(['confirmations' => $probe, 'preparation' => $probe],
            function ($kind, $buffer) use (&$output) { $output .= $buffer; },
            fn () => microtime(true) - $started > 0.4);
        $this->assertSame(0, $result);
        $pids = array_filter(preg_split('/\s+/', trim($output)), fn ($id) => ctype_digit($id));
        $this->assertCount(2, $pids);
        foreach ($pids as $pid) { $this->assertFalse(@posix_kill((int) $pid, 0)); }
    }

    #[Test]
    public function failed_child_stops_the_other_instead_of_leaving_only_confirmations(): void
    {
        $output = ''; $started = microtime(true);
        $result = (new LocalQaWorkerSupervisor)->run([
            'confirmations' => [PHP_BINARY, '-r', 'usleep(5000000);'],
            'preparation' => [PHP_BINARY, '-r', 'exit(7);'],
        ], function ($kind, $buffer) use (&$output) { $output .= $kind.':'.$buffer; }, fn () => false);
        $this->assertSame(1, $result);
        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertStringContainsString('preparation:Worker terminado', $output);
    }

    #[Test]
    public function command_refuses_disabled_qa_without_starting_any_process(): void
    {
        config(['local-checkout-qa.enabled' => false]);
        $supervisor = \Mockery::mock(LocalQaWorkerSupervisor::class);
        $supervisor->shouldNotReceive('run');
        app()->instance(LocalQaWorkerSupervisor::class, $supervisor);
        $this->artisan('local-qa:listen')->expectsOutputToContain('No se iniciaron workers')->assertFailed();
    }
}
