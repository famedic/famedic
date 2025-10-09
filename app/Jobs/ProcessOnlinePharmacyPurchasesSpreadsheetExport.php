<?php

namespace App\Jobs;

use App\Exports\OnlinePharmacyPurchasesExport;
use App\Models\User;
use App\Notifications\SpreadsheetExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessOnlinePharmacyPurchasesSpreadsheetExport implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public function __construct(public User $user, public array $filters = []) {}

    public function handle(): void
    {
        $fileName = 'online-pharmacy/exports/'.$this->user->email.'/online-pharmacy '.localizedDate(now())->format('jS M g.i').strtolower(localizedDate(now())->format('A')).'.xlsx';

        Excel::store(new OnlinePharmacyPurchasesExport($this->filters), $fileName);

        $downloadLink = Storage::temporaryUrl($fileName, now()->addMinutes(120));

        $this->user->notify(new SpreadsheetExportReady($downloadLink));
    }
}
