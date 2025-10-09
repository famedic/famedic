<?php

namespace App\Jobs;

use App\Exports\CustomersExport;
use App\Models\User;
use App\Notifications\SpreadsheetExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessCustomersSpreadsheetExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public array $filters = []) {}

    public function handle(): void
    {
        $fileName = 'customers/exports/'.$this->user->email.'/customers '.localizedDate(now())->format('jS M g.i').strtolower(localizedDate(now())->format('A')).'.xlsx';

        Excel::store(new CustomersExport($this->filters), $fileName);

        $downloadLink = Storage::temporaryUrl($fileName, now()->addMinutes(120));

        $this->user->notify(new SpreadsheetExportReady($downloadLink));
    }
}
