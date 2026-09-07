<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Exports\LaboratoryBilling\LaboratoryBillingAutomaticReportExport;
use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Notifications\LaboratoryBillingAutomaticReportNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;

class LaboratoryBillingReportDeliveryService
{
    public function deliver(LaboratoryBillingReportSchedule $schedule, LaboratoryBillingReportRun $run, array $reportData): void
    {
        $reportData['_included_sections'] = $schedule->included_sections ?? [];
        $downloadUrl = null;
        $attachmentPath = null;

        if ($schedule->include_excel) {
            [$downloadUrl, $attachmentPath] = $this->generateExcel($run, $reportData);
        } else {
            $run->update(['delivery_method' => 'email_only']);
        }

        foreach ($run->recipients ?? [] as $recipient) {
            Notification::route('mail', $recipient)
                ->notify(new LaboratoryBillingAutomaticReportNotification(
                    $schedule,
                    $run,
                    $reportData,
                    $downloadUrl,
                    $attachmentPath,
                ));
        }
    }

    private function generateExcel(LaboratoryBillingReportRun $run, array $reportData): array
    {
        $disk = (string) config('famedic.laboratory_billing.report_disk', 'local');
        $path = 'laboratory-billing/reports/'.$run->id.'/reporte-facturacion-laboratorio.xlsx';

        Excel::store(new LaboratoryBillingAutomaticReportExport($reportData), $path, $disk);

        $size = Storage::disk($disk)->size($path);
        $linkExpiresAt = now()->addHours((int) config('famedic.laboratory_billing.report_link_ttl_hours', 72));
        $maxAttachmentBytes = (int) config('famedic.laboratory_billing.report_max_attachment_bytes', 8 * 1024 * 1024);

        $run->update([
            'file_disk' => $disk,
            'file_path' => $path,
            'file_size' => $size,
            'link_expires_at' => $linkExpiresAt,
        ]);

        if ($size <= $maxAttachmentBytes && method_exists(Storage::disk($disk), 'path')) {
            try {
                $run->update(['delivery_method' => 'attachment']);

                return [null, Storage::disk($disk)->path($path)];
            } catch (\Throwable) {
                // Some remote disks do not expose a local path; use the signed route instead.
            }
        }

        $url = URL::temporarySignedRoute(
            'admin.laboratory-billing.automatic-runs.download',
            $linkExpiresAt,
            ['run' => $run->id]
        );

        $run->update(['delivery_method' => 'link']);

        return [$url, null];
    }
}
