<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Exports\LaboratoryBilling\LaboratoryBillingAutomaticReportExport;
use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Notifications\LaboratoryBillingAutomaticReportNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
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

        Log::info('[Laboratory Billing Report] delivery started', [
            'schedule_id' => $schedule->id,
            'run_id' => $run->id,
            'include_excel' => (bool) $schedule->include_excel,
            'recipients_count' => count($run->recipients ?? []),
        ]);

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

            Log::info('[Laboratory Billing Report] mail notification submitted', [
                'schedule_id' => $schedule->id,
                'run_id' => $run->id,
                'recipient' => $this->maskedEmail((string) $recipient),
                'delivery_method' => $run->fresh()->delivery_method,
                'has_attachment' => $attachmentPath !== null,
                'has_download_link' => $downloadUrl !== null,
            ]);
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

                Log::info('[Laboratory Billing Report] excel generated for attachment', [
                    'run_id' => $run->id,
                    'disk' => $disk,
                    'path' => $path,
                    'file_size' => $size,
                ]);

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

        Log::info('[Laboratory Billing Report] excel generated for signed link', [
            'run_id' => $run->id,
            'disk' => $disk,
            'path' => $path,
            'file_size' => $size,
            'link_expires_at' => $linkExpiresAt->toIso8601String(),
        ]);

        return [$url, null];
    }

    private function maskedEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return 'invalid-recipient';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = mb_substr($local, 0, 1);

        return $prefix.'***@'.$domain;
    }
}
