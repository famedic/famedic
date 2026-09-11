<?php

namespace App\Notifications;

use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LaboratoryBillingAutomaticReportNotification extends Notification
{
    use Queueable;

    private const ATTACHMENT_NAME = 'reporte-facturacion-laboratorio.xlsx';

    private const ATTACHMENT_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private LaboratoryBillingReportSchedule $schedule,
        private LaboratoryBillingReportRun $run,
        private array $reportData,
        private ?string $downloadUrl = null,
        private ?string $attachmentDisk = null,
        private ?string $attachmentPath = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metrics = $this->reportData['metrics'] ?? [];
        $isTest = $this->run->run_type === LaboratoryBillingReportRun::TYPE_TEST;
        $period = $this->reportData['period'] ?? [];
        $periodRange = $this->periodRangeForSubject($period);
        $attachmentData = $this->attachmentData();

        $mail = (new MailMessage)
            ->subject(($isTest ? '[PRUEBA] ' : '').'Reporte de facturación | '.$this->schedule->name.($periodRange ? ' | '.$periodRange : ''))
            ->view('emails.laboratory-billing.automatic-report', [
                'schedule' => $this->schedule,
                'run' => $this->run,
                'reportData' => $this->reportData,
                'metrics' => $metrics,
                'downloadUrl' => $this->downloadUrl,
                'attachmentPath' => $attachmentData !== null ? $this->attachmentPath : null,
                'famedicLogoUrl' => $this->emailPublicAssetUrl('images/logo.png'),
                'moduleUrl' => route('admin.laboratory-billing.automatic-reports.index'),
                'isTest' => $isTest,
            ]);

        if ($attachmentData !== null) {
            $mail->attachData($attachmentData, self::ATTACHMENT_NAME, [
                'mime' => self::ATTACHMENT_MIME,
            ]);
        }

        return $mail;
    }

    private function attachmentData(): ?string
    {
        if (! $this->attachmentDisk || ! $this->attachmentPath) {
            return null;
        }

        if (! Storage::disk($this->attachmentDisk)->exists($this->attachmentPath)) {
            Log::error('[Laboratory Billing Report] attachment file missing', [
                'run_id' => $this->run->id,
                'disk' => $this->attachmentDisk,
                'path' => $this->attachmentPath,
            ]);

            return null;
        }

        return Storage::disk($this->attachmentDisk)->get($this->attachmentPath);
    }

    private function periodRangeForSubject(array $period): string
    {
        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;

        if (! $start || ! $end) {
            return '';
        }

        return localizedDate($start)?->format('d/m/Y').'–'.localizedDate($end)?->format('d/m/Y');
    }

    private function emailPublicAssetUrl(string $path): string
    {
        $base = rtrim((string) config('famedic.email_public_url'), '/');
        $path = ltrim($path, '/');

        return $base.'/'.$path;
    }
}
