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
        $aging = $metrics['aging'] ?? [];
        $missing = $metrics['missing_files'] ?? [];
        $sections = $this->reportData['_included_sections'] ?? [];
        $include = fn (string $section): bool => $sections === [] || in_array($section, $sections, true);
        $isTest = $this->run->run_type === LaboratoryBillingReportRun::TYPE_TEST;

        $mail = (new MailMessage)
            ->subject(($isTest ? '[PRUEBA] ' : '').'Reporte de facturación: '.$this->schedule->name)
            ->greeting(($isTest ? 'Envío de prueba · ' : '').$this->schedule->name)
            ->line('Generado: '.localizedDate($this->run->started_at ?? now())?->isoFormat('D MMM Y h:mm a'))
            ->line('Periodo de actividad: '.($this->reportData['period']['label'] ?? ''))
            ->line('Este reporte separa actividad del periodo y backlog pendiente al momento del corte.');

        if ($isTest) {
            $mail->line('PRUEBA: este mensaje valida configuración y contenido; no representa una ejecución programada operativa.');
        }

        if ($include('activity')) {
            $mail->line('Solicitudes recibidas en el periodo: '.($metrics['received'] ?? 0))
                ->line('Cumplimiento: '.($metrics['compliance_percent'] ?? 0).'%');
        }

        if ($include('completed')) {
            $mail->line('Facturas completadas en el periodo: '.($metrics['completed'] ?? 0))
                ->line('Tiempo promedio de atención: '.(($metrics['average_response_hours'] ?? null) === null ? 'Sin datos' : $metrics['average_response_hours'].' h'));
        }

        if ($include('backlog')) {
            $mail->line('Pendientes actuales: '.($metrics['pending_backlog'] ?? 0))
                ->line('Solicitud pendiente más antigua: '.(data_get($metrics, 'oldest_pending.formatted_requested_at') ?: 'Sin pendientes'));
        }

        if ($include('overdue')) {
            $mail->line('Solicitudes atrasadas: '.($metrics['overdue_backlog'] ?? 0));
        }

        if ($include('aging')) {
            $mail->line('Antigüedad: dentro de plazo '.($aging['within_sla'] ?? 0).', 1-3 días '.($aging['overdue_1_3'] ?? 0).', 4-7 días '.($aging['overdue_4_7'] ?? 0).', más de 7 días '.($aging['overdue_more_7'] ?? 0).'.');
        }

        if ($include('missing_files')) {
            $mail->line('Archivos faltantes: falta PDF '.($missing['missing_pdf'] ?? 0).', falta XML '.($missing['missing_xml'] ?? 0).', faltan ambos '.($missing['missing_both'] ?? 0).'.');
        }

        if ($metrics['detail_truncated'] ?? false) {
            $mail->line('El detalle fue limitado a '.($metrics['detail_exported_rows'] ?? 0).' de '.($metrics['detail_total_rows'] ?? 0).' filas para evitar archivos demasiado grandes.');
        }

        $mail->action('Abrir facturación', route('admin.laboratory-billing.automatic-reports.index'));

        if ($this->downloadUrl) {
            $mail->line('El Excel está disponible mediante enlace temporal protegido.')
                ->action('Descargar Excel', $this->downloadUrl);
        }

        if ($this->attachmentPath) {
            $mail->attach($this->attachmentPath, [
                'as' => 'reporte-facturacion-laboratorio.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $mail;
    }
}
