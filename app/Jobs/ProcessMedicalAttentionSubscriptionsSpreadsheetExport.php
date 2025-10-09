<?php

namespace App\Jobs;

use App\Exports\MedicalAttentionSubscriptionsExport;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;
use App\Notifications\SpreadsheetExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessMedicalAttentionSubscriptionsSpreadsheetExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public array $filters = []) {}

    public function handle(): void
    {
        $subscriptions = MedicalAttentionSubscription::with([
            'customer.user',
            'customer.customerable',
            'customer.familyMembers',
            'transactions',
        ])
            ->filter($this->filters)
            ->latest()
            ->get();

        $fileName = 'medical-attention-subscriptions/exports/'.$this->user->email.'/medical-attention-subscriptions '.localizedDate(now())->format('jS M g.i').strtolower(localizedDate(now())->format('A')).'.xlsx';

        Excel::store(new MedicalAttentionSubscriptionsExport($subscriptions), $fileName);

        $downloadLink = Storage::temporaryUrl($fileName, now()->addMinutes(120));

        $this->user->notify(new SpreadsheetExportReady($downloadLink));
    }
}
