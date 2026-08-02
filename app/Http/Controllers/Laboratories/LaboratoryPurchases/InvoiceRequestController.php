<?php

namespace App\Http\Controllers\Laboratories\LaboratoryPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreInvoiceRequestRequest;
use App\Models\Administrator;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\TaxProfile;
use App\Models\User;
use App\Notifications\LaboratoryPurchaseInvoiceRequested;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class InvoiceRequestController extends Controller
{
    /**
     * Método invocable - Procesa la solicitud de factura para una compra de laboratorio
     * 
     * @param StoreInvoiceRequestRequest $request - Validación de los datos del formulario
     * @param LaboratoryPurchase $laboratoryPurchase - Modelo de la compra de laboratorio
     * @param CreateInvoiceRequestAction $action - Action que crea la solicitud de factura
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(
        StoreInvoiceRequestRequest $request, 
        LaboratoryPurchase $laboratoryPurchase, 
        CreateInvoiceRequestAction $action
    )
    {
        Log::info('Iniciando solicitud de factura', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'user_id' => auth()->id(),
            'customer_id' => auth()->user()->customer->id,
            'tax_profile_id' => $request->tax_profile,
            'operation' => 'laboratory_invoice_request',
        ]);

        // 1. OBTENER Y ACTUALIZAR PERFIL FISCAL CON EL CFDI USE SELECCIONADO
        // --------------------------------------------------------------------
        $taxProfile = auth()->user()->customer->taxProfiles()->find($request->tax_profile);

        if (! $taxProfile) {
            Log::warning('Solicitud de factura laboratorio: perfil fiscal no encontrado para el customer autenticado.', [
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()->customer->id,
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'operation' => 'laboratory_invoice_request',
            ]);

            return redirect()->back()->withErrors(['tax_profile' => 'Perfil fiscal no encontrado.']);
        }

        $cfdiUse = $request->validated('cfdi_use');

        // CFDI elegido solo en el snapshot (CreateInvoiceRequestAction). No mutar el perfil vivo.

        Log::info('Ejecutando CreateInvoiceRequestAction', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'tax_profile_id' => $taxProfile->id,
            'operation' => 'laboratory_invoice_request',
        ]);
        $action($laboratoryPurchase, $taxProfile, $cfdiUse);

        // 3. NOTIFICACIONES A EQUIPO DE FACTURACIÓN (LaboratoryPurchaseInvoiceRequested → correo)
        // ---------------------------------------------------------------------------
        // Producción: todos los User vinculados a administradores con permiso
        // laboratory-purchases.manage.invoices (vía roles Spatie).
        // local/staging/testing: por defecto solo el usuario cuyo email coincide con
        // LAB_INVOICE_REQUEST_TEST_EMAIL (si existe en BD). Sin fallback a admins salvo que
        // LAB_INVOICE_REQUEST_FALLBACK_TO_ADMINS=true.
        // SKIP_LABORATORY_INVOICE_REQUEST_MAIL=true omite cualquier envío (recomendado en local).
        $environment = App::environment();
        $isTestEnvironment = in_array($environment, ['local', 'staging', 'testing'], true);
        $skipMail = (bool) config('services.laboratory_invoice_request.skip_admin_mail');

        $users = collect();

        if ($skipMail) {
            Log::info('Solicitud de factura: omitiendo correos a administradores (SKIP_LABORATORY_INVOICE_REQUEST_MAIL).', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'environment' => $environment,
            ]);
        } elseif ($isTestEnvironment) {
            $testEmail = (string) config('services.laboratory_invoice_request.test_notify_email');
            Log::info('Entorno no productivo: notificación de solicitud de factura (laboratorio)', [
                'environment' => $environment,
                'test_email' => $testEmail,
                'fallback_to_admins' => (bool) config('services.laboratory_invoice_request.allow_fallback_to_invoice_admins'),
            ]);

            $testUser = $testEmail !== '' ? User::where('email', $testEmail)->first() : null;
            if ($testUser) {
                $users->push($testUser);
                Log::info('Usuario de prueba encontrado para notificación de factura.', ['user_id' => $testUser->id]);
            } elseif (config('services.laboratory_invoice_request.allow_fallback_to_invoice_admins')) {
                Log::warning('Usuario de prueba no encontrado; fallback a administradores con permiso de facturas (LAB_INVOICE_REQUEST_FALLBACK_TO_ADMINS).');
                $users = $this->invoiceNotificationRecipients();
            } else {
                Log::warning('Usuario de prueba no encontrado y fallback desactivado: no se envía correo de solicitud de factura.');
            }
        } else {
            Log::info('Entorno de producción: notificación de solicitud de factura a administradores con permiso.', [
                'environment' => $environment,
            ]);
            $users = $this->invoiceNotificationRecipients();
        }

        // 4. ENVIAR NOTIFICACIONES
        // -------------------------
        Log::info('Enviando notificaciones de solicitud de factura (laboratorio)', [
            'total_users' => $users->count(),
            'is_test_environment' => $isTestEnvironment,
            'skipped' => $skipMail,
        ]);

        foreach ($users as $user) {
            $user->notify(new LaboratoryPurchaseInvoiceRequested($laboratoryPurchase));
            Log::info('Notificación enviada', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
        }

        // 5. OBTENER NOMBRE DEL USO CFDI PARA EL MENSAJE FLASH
        // -----------------------------------------------------
        $cfdiUses = config('taxregimes.uses', []);
        $cfdiUseName = $cfdiUses[$cfdiUse] ?? $cfdiUse;

        // 6. REDIRECCIONAR CON MENSAJE DE ÉXITO PERSONALIZADO
        // ----------------------------------------------------
        $message = 'Se ha solicitado la factura y estará disponible después de 72 horas hábiles. ';
        $message .= "Uso de CFDI: {$cfdiUse} - {$cfdiUseName}";

        if ($isTestEnvironment) {
            $message .= " [Entorno de prueba: {$environment}]";
        }

        Log::info('Solicitud de factura laboratorio completada', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'tax_profile_id' => $taxProfile->id,
            'operation' => 'laboratory_invoice_request',
            'result' => 'success',
        ]);

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage($message);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    private function invoiceNotificationRecipients(): \Illuminate\Support\Collection
    {
        $users = collect();
        $roles = Permission::whereName('laboratory-purchases.manage.invoices')->sole()->roles;

        foreach ($roles as $role) {
            $administrators = Administrator::role($role->name)->get();
            $users = $users->merge($administrators->pluck('user'));
        }

        return $users->unique('id')->values();
    }
}