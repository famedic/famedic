<?php

namespace App\Jobs;

use App\Services\Murguia\MurguiaInsuredExcelRowProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMurguiaRowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public array $row,
        public int $rowNumber
    ) {
        $this->onQueue(config('services.murguia.queue', 'default'));
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(MurguiaInsuredExcelRowProcessor $processor): void
    {
        $processor->process($this->row, $this->rowNumber);
    }
}
