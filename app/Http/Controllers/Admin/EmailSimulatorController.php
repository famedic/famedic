<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Notifications\CustomerLaboratoryPurchaseDeleted;
use App\Notifications\FewDaysLeftToRequestInvoice;
use App\Notifications\LaboratoryAppointmentUpdatedByConcierge;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Notifications\LaboratoryPurchaseInvoiceRequested;
use App\Notifications\LaboratoryPurchaseResultsUploaded;
use App\Notifications\LaboratoryResultsAvailable;
use App\Notifications\LaboratoryResultsOtpNotification;
use App\Notifications\LaboratorySampleCollected;
use App\Notifications\PurchaseInvoiceUploaded;
use App\Notifications\ReferralSignupNotification;
use App\Notifications\SpreadsheetExportReady;
use App\Services\Laboratory\LabResultsAccessTokenService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EmailSimulatorController extends Controller
{
    /** @var list<string> */
    private const PREVIEW_TYPES = [
        'verify_email',
        'password_reset',
        'laboratory_results_otp',
        'laboratory_purchase_created',
        'laboratory_purchase_created_without_appointment',
        'laboratory_appointment_updated',
        'customer_laboratory_purchase_deleted',
        'laboratory_sample_collected',
        'laboratory_results_available',
        'laboratory_results_available_with_pdf',
        'purchase_invoice_uploaded',
        'few_days_left_to_request_invoice',
        'laboratory_purchase_invoice_requested',
        'laboratory_purchase_results_uploaded',
        'laboratory_purchase_pdf_shared',
        'spreadsheet_export_ready',
        'referral_signup',
    ];

    public function index(Request $request): Response
    {
        $this->ensureSimulatorAccess($request);

        $purchases = LaboratoryPurchase::query()
            ->with(['customer.user:id,name,paternal_lastname,maternal_lastname,email'])
            ->latest('id')
            ->limit(40)
            ->get(['id', 'customer_id', 'gda_order_id', 'created_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $user = $purchase->customer?->user;
                $customerLabel = $user
                    ? trim("{$user->name} {$user->paternal_lastname} {$user->maternal_lastname}")
                    : ('Cliente #'.$purchase->customer_id);

                return [
                    'id' => $purchase->id,
                    'gda_order_id' => $purchase->gda_order_id,
                    'created_at' => $purchase->formatted_created_at ?? $purchase->created_at?->format('d/m/Y H:i'),
                    'customer_label' => $customerLabel !== '' ? $customerLabel : ($user?->email ?? 'Cliente #'.$purchase->customer_id),
                    'has_customer_user' => $user !== null,
                ];
            });

        $suggested = $purchases->first(fn (array $p) => ($p['has_customer_user'] ?? false) === true);

        return Inertia::render('Admin/Simulators/Emails', [
            'purchases' => $purchases,
            'suggestedPurchaseId' => $suggested['id'] ?? null,
            'emailGroups' => $this->emailGroups(),
        ]);
    }

    public function preview(Request $request, string $type): SymfonyResponse
    {
        $this->ensureSimulatorAccess($request);

        if (! in_array($type, self::PREVIEW_TYPES, true)) {
            return $this->htmlError('Tipo de correo no reconocido.', 404);
        }

        $validator = Validator::make($request->query(), [
            'laboratory_purchase' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        $fail('El identificador del pedido no es válido.');

                        return;
                    }
                    if (! LaboratoryPurchase::withTrashed()->whereKey((int) $value)->exists()) {
                        $fail('No existe un pedido de laboratorio con ese identificador.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return $this->htmlError('Selecciona un pedido de laboratorio válido (parámetro laboratory_purchase).', 422);
        }

        $purchaseId = (int) $validator->validated()['laboratory_purchase'];

        $purchase = LaboratoryPurchase::query()
            ->withTrashed()
            ->with([
                'customer.user',
                'laboratoryPurchaseItems',
                'laboratoryAppointment.laboratoryStore',
                'laboratoryAppointment.customer.laboratoryCartItems.laboratoryTest',
                'transactions',
            ])
            ->findOrFail($purchaseId);

        $user = $purchase->customer?->user;
        if ($user === null) {
            return $this->htmlError(
                'Este pedido no tiene un usuario (cuenta) asociado. Elige otro pedido donde el cliente tenga usuario en Famedic.',
                422
            );
        }

        try {
            $mail = $this->buildMailMessage($type, $purchase, $user);
        } catch (\Throwable $e) {
            report($e);

            return $this->htmlError(
                'No se pudo generar la vista previa: '.$e->getMessage(),
                500
            );
        }

        return response((string) $mail->render(), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * @return list<array{title: string, items: list<array{key: string, title: string, description: string}>}>
     */
    private function emailGroups(): array
    {
        return [
            [
                'title' => 'Autenticación y acceso',
                'items' => [
                    [
                        'key' => 'verify_email',
                        'title' => 'Verificación de correo (registro)',
                        'description' => 'Correo estándar de Laravel para confirmar la dirección de correo al registrarse.',
                    ],
                    [
                        'key' => 'password_reset',
                        'title' => 'Restablecimiento de contraseña',
                        'description' => 'Enlace para crear una nueva contraseña (flujo “olvidé mi contraseña”).',
                    ],
                    [
                        'key' => 'laboratory_results_otp',
                        'title' => 'Código OTP para ver resultados de laboratorio',
                        'description' => 'Mismo contenido que el correo cuando el paciente pide el código por email (canal correo).',
                    ],
                ],
            ],
            [
                'title' => 'Compras y citas de laboratorio',
                'items' => [
                    [
                        'key' => 'laboratory_purchase_created',
                        'title' => 'Confirmación de compra de laboratorio (automático)',
                        'description' => 'Elige plantilla con o sin cita según el pedido seleccionado (mismo comportamiento que el envío real).',
                    ],
                    [
                        'key' => 'laboratory_purchase_created_without_appointment',
                        'title' => 'Confirmación de compra sin cita',
                        'description' => 'Plantilla purchase-without-appointment.blade.php: comprobante e instrucciones para presentarse en sucursal.',
                    ],
                    [
                        'key' => 'laboratory_appointment_updated',
                        'title' => 'Cita de laboratorio actualizada',
                        'description' => 'Notificación cuando conserjería actualiza la cita. Requiere que el pedido tenga cita vinculada.',
                    ],
                ],
            ],
            [
                'title' => 'Cancelación',
                'items' => [
                    [
                        'key' => 'customer_laboratory_purchase_deleted',
                        'title' => 'Cancelación de orden de laboratorio',
                        'description' => 'Aviso al cliente cuando se cancela su orden y se inicia reembolso.',
                    ],
                ],
            ],
            [
                'title' => 'Muestras y resultados',
                'items' => [
                    [
                        'key' => 'laboratory_sample_collected',
                        'title' => 'Toma de muestra realizada',
                        'description' => 'Confirmación de toma de muestra con detalle de orden y fecha.',
                    ],
                    [
                        'key' => 'laboratory_results_available',
                        'title' => 'Resultados disponibles',
                        'description' => 'Correo principal cuando los resultados ya se pueden consultar (incluye botón de acceso).',
                    ],
                    [
                        'key' => 'laboratory_results_available_with_pdf',
                        'title' => 'Resultados disponibles (texto con PDF en payload)',
                        'description' => 'Variante del mismo correo cuando el aviso indica PDF adjunto en el sistema.',
                    ],
                    [
                        'key' => 'laboratory_purchase_results_uploaded',
                        'title' => 'Resultados disponibles (mensaje breve)',
                        'description' => 'Versión corta: resultados listos con enlace a la orden.',
                    ],
                ],
            ],
            [
                'title' => 'Facturación',
                'items' => [
                    [
                        'key' => 'purchase_invoice_uploaded',
                        'title' => 'Factura disponible',
                        'description' => 'Aviso al cliente de que ya puede descargar la factura de la orden de laboratorio.',
                    ],
                    [
                        'key' => 'few_days_left_to_request_invoice',
                        'title' => 'Recordatorio: plazo para solicitar factura',
                        'description' => 'Recordatorio de días restantes para solicitar factura antes del cierre de mes.',
                    ],
                    [
                        'key' => 'laboratory_purchase_invoice_requested',
                        'title' => 'Solicitud de factura (equipo interno)',
                        'description' => 'Correo a quien factura con enlace al detalle en el panel admin.',
                    ],
                ],
            ],
            [
                'title' => 'Otros correos del sistema',
                'items' => [
                    [
                        'key' => 'laboratory_purchase_pdf_shared',
                        'title' => 'Orden de laboratorio compartida (PDF)',
                        'description' => 'Texto del correo al compartir la orden; la vista previa no incluye el adjunto PDF.',
                    ],
                    [
                        'key' => 'spreadsheet_export_ready',
                        'title' => 'Exportación de Excel / reporte listo',
                        'description' => 'Enlace de descarga cuando termina un reporte generado desde el panel.',
                    ],
                    [
                        'key' => 'referral_signup',
                        'title' => 'Referidos: alguien se registró con tu enlace',
                        'description' => 'Aviso al usuario que invitó cuando un nuevo usuario se registra con su referido.',
                    ],
                ],
            ],
        ];
    }

    private function buildMailMessage(string $type, LaboratoryPurchase $purchase, User $user): MailMessage
    {
        return match ($type) {
            'verify_email' => (new VerifyEmail)->toMail($user),
            'password_reset' => (new ResetPassword('simulator-preview-token'))->toMail($user),
            'laboratory_results_otp' => (new LaboratoryResultsOtpNotification('123456', 'email'))->toMail($user),
            'laboratory_purchase_created' => (new LaboratoryPurchaseCreated($purchase))->toMail($user),
            'laboratory_purchase_created_without_appointment' => $this->purchaseCreatedWithoutAppointmentMail($purchase, $user),
            'laboratory_appointment_updated' => $this->appointmentUpdatedMail($purchase, $user),
            'customer_laboratory_purchase_deleted' => (new CustomerLaboratoryPurchaseDeleted($purchase))->toMail($user),
            'laboratory_sample_collected' => (new LaboratorySampleCollected($purchase))->toMail($user),
            'laboratory_results_available' => $this->resultsAvailableMail($purchase, $user, false),
            'laboratory_results_available_with_pdf' => $this->resultsAvailableMail($purchase, $user, true),
            'purchase_invoice_uploaded' => (new PurchaseInvoiceUploaded($purchase))->toMail($user),
            'few_days_left_to_request_invoice' => (new FewDaysLeftToRequestInvoice($purchase, 3))->toMail($user),
            'laboratory_purchase_invoice_requested' => (new LaboratoryPurchaseInvoiceRequested($purchase))->toMail($user),
            'laboratory_purchase_results_uploaded' => (new LaboratoryPurchaseResultsUploaded($purchase))->toMail($user),
            'laboratory_purchase_pdf_shared' => $this->pdfSharedPreviewWithoutAttachment($purchase),
            'spreadsheet_export_ready' => (new SpreadsheetExportReady(
                url('/admin?simulator_export_preview=1')
            ))->toMail($user),
            'referral_signup' => $this->referralSignupMail($user),
        };
    }

    private function purchaseCreatedWithoutAppointmentMail(LaboratoryPurchase $purchase, User $user): MailMessage
    {
        $preview = (new LaboratoryPurchaseCreated($purchase))->previewMail($user, 'without');

        return (new MailMessage)
            ->subject($preview['subject'])
            ->markdown($preview['view'], $preview['data']);
    }

    private function appointmentUpdatedMail(LaboratoryPurchase $purchase, User $user): MailMessage
    {
        $appointment = $purchase->laboratoryAppointment;
        if ($appointment === null) {
            return (new MailMessage)
                ->subject('Vista previa no disponible')
                ->line('Este pedido no tiene una cita de laboratorio vinculada.')
                ->line('Elige otro pedido con cita programada para ver el correo “Cita actualizada”.');
        }

        return (new LaboratoryAppointmentUpdatedByConcierge($appointment))->toMail($user);
    }

    private function resultsAvailableMail(LaboratoryPurchase $purchase, User $user, bool $withPdf): MailMessage
    {
        $stub = new class extends LabResultsAccessTokenService
        {
            public function generate(User $user, LaboratoryPurchase $purchase): string
            {
                return str_repeat('a', 64);
            }
        };

        app()->instance(LabResultsAccessTokenService::class, $stub);

        try {
            return (new LaboratoryResultsAvailable($purchase, null, null, $withPdf))->toMail($user);
        } finally {
            app()->forgetInstance(LabResultsAccessTokenService::class);
        }
    }

    private function referralSignupMail(User $referrer): MailMessage
    {
        $newUser = User::query()
            ->whereKeyNot($referrer->id)
            ->orderByDesc('id')
            ->first();

        if ($newUser === null) {
            return (new MailMessage)
                ->subject('Vista previa de referidos')
                ->line('No hay otro usuario en la base de datos para simular el “nuevo registro”. Crea un usuario de prueba adicional o ignora esta vista previa.');
        }

        return (new ReferralSignupNotification($newUser))->toMail($referrer);
    }

    private function pdfSharedPreviewWithoutAttachment(LaboratoryPurchase $purchase): MailMessage
    {
        $displayOrderId = ! empty($purchase->gda_order_id)
            ? $purchase->gda_order_id
            : "#{$purchase->id}";

        $senderName = 'Administrador (simulador)';

        return (new MailMessage)
            ->subject('Orden de Laboratorio - '.$displayOrderId)
            ->greeting('¡Hola!')
            ->line($senderName.' te ha compartido una orden de laboratorio de Famedic.')
            ->line('**Detalles de la orden:**')
            ->line('Folio: **'.$displayOrderId.'**')
            ->line('Paciente: **'.$purchase->full_name.'**')
            ->line('Total: **'.$purchase->formatted_total.'**')
            ->line('En el correo real se adjunta el PDF de la orden. Esta vista previa solo muestra el texto.')
            ->line('Para más información sobre Famedic, visita nuestra página web.');
    }

    private function htmlError(string $message, int $status): SymfonyResponse
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return response(
            '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Vista previa</title></head>'
            .'<body style="font-family:system-ui,sans-serif;padding:2rem;">'
            .'<h1 style="font-size:1.1rem;">Vista previa de correo</h1>'
            .'<p style="color:#b91c1c;">'.$safe.'</p>'
            .'</body></html>',
            $status
        )->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function ensureSimulatorAccess(Request $request): void
    {
        $request->user()->administrator->hasPermissionTo('simulators.manage') || abort(403);
    }
}
