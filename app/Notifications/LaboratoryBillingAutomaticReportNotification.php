<?php

namespace App\Notifications;

use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaboratoryBillingAutomaticReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        private LaboratoryBillingReportSchedule $schedule,
        private LaboratoryBillingReportRun $run,
        private array $reportData,
        private ?string $downloadUrl = null,
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

        $mail = (new MailMessage)
            ->subject(($isTest ? '[PRUEBA] ' : '').'Reporte de facturación | '.$this->schedule->name.($periodRange ? ' | '.$periodRange : ''))
            ->view('emails.laboratory-billing.automatic-report', [
                'schedule' => $this->schedule,
                'run' => $this->run,
                'reportData' => $this->reportData,
                'metrics' => $metrics,
                'downloadUrl' => $this->downloadUrl,
                'attachmentPath' => $this->attachmentPath,
                'famedicLogoUrl' => $this->emailPublicAssetUrl('images/logo.png'),
                'moduleUrl' => route('admin.laboratory-billing.automatic-reports.index'),
                'isTest' => $isTest,
            ]);

        if ($this->attachmentPath) {
            $mail->attach($this->attachmentPath, [
                'as' => 'reporte-facturacion-laboratorio.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $mail;
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
