<?php

namespace App\Jobs;

use App\Exports\CartsExport;
use App\Models\User;
use App\Notifications\SpreadsheetExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessCartsSpreadsheetExport implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public User $user;

    public array $filters;

    public function __construct(User $user, array $filters = [])
    {
        $this->user = $user;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        $filters = CartsExport::normalizeFilters($this->filters);
        $fileName = 'carts/exports/'.$this->user->email.'/carritos-famedic-'.CartsExport::fileDateSegment($filters).'.xlsx';

        Excel::store(new CartsExport($filters), $fileName);

        $downloadLink = Storage::temporaryUrl($fileName, now()->addMinutes(120));

        $this->user->notify(new SpreadsheetExportReady($downloadLink));
    }
}
