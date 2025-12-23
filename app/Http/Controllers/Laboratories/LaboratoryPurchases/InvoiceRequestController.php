<?php

namespace App\Http\Controllers\Laboratories\LaboratoryPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laboratories\LaboratoryPurchases\StoreInvoiceRequestRequest;
use App\Models\Administrator;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\TaxProfile;
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
            'tax_profile_id' => $request->tax_profile,
            'cfdi_use' => $request->cfdi_use
        ]);

        // 1. OBTENER Y ACTUALIZAR PERFIL FISCAL CON EL CFDI USE SELECCIONADO
        // --------------------------------------------------------------------
        $taxProfile = auth()->user()->customer->taxProfiles()->find($request->tax_profile);
        
        if (!$taxProfile) {
            Log::error('Perfil fiscal no encontrado', [
                'tax_profile_id' => $request->tax_profile,
                'customer_id' => auth()->user()->customer->id
            ]);
            return redirect()->back()->withErrors(['tax_profile' => 'Perfil fiscal no encontrado.']);
        }

        // Verificar si el CFDI use es diferente al registrado
        if ($request->cfdi_use && $taxProfile->cfdi_use !== $request->cfdi_use) {
            Log::info('Actualizando CFDI use del perfil fiscal', [
                'tax_profile_id' => $taxProfile->id,
                'old_cfdi_use' => $taxProfile->cfdi_use,
                'new_cfdi_use' => $request->cfdi_use
            ]);
            
            // Actualizar el perfil fiscal con el nuevo CFDI use
            $taxProfile->update([
                'cfdi_use' => $request->cfdi_use
            ]);
            
            Log::info('Perfil fiscal actualizado exitosamente');
        }

        // 2. EJECUTAR LA ACCIÓN PRINCIPAL - Crear solicitud de factura
        // --------------------------------------------------------------
        Log::info('Ejecutando CreateInvoiceRequestAction');
        $action($laboratoryPurchase, $taxProfile);

        // 3. OBTENER ADMINISTRADORES PARA NOTIFICAR
        // -----------------------------------------
        // Si estamos en ambiente local, staging o testing, solo notificar a emedina.externo@odessa.com.mx
        $environment = App::environment();
        $isTestEnvironment = in_array($environment, ['local', 'staging', 'testing']);
        
        $users = collect();
        
        if ($isTestEnvironment) {
            Log::info('Entorno de prueba detectado, usando correo de prueba', [
                'environment' => $environment,
                'test_email' => 'emedina.externo@odessa.com.mx'
            ]);
            
            // Buscar administrador con el correo específico
            $testUser = \App\Models\User::where('email', 'emedina.externo@odessa.com.mx')->first();
            if ($testUser) {
                $users->push($testUser);
                Log::info('Usuario de prueba encontrado', ['user_id' => $testUser->id]);
            } else {
                Log::warning('Usuario de prueba no encontrado, intentando fallback a administradores');
                
                // Fallback: usar administradores como antes
                $roles = Permission::whereName('laboratory-purchases.manage.invoices')->sole()->roles;
                
                foreach ($roles as $role) {
                    $administrators = Administrator::role($role->name)->get();
                    $users = $users->merge($administrators->pluck('user'));
                }
                
                $users = $users->unique('id');
            }
        } else {
            // Entorno de producción: usar lógica normal
            Log::info('Entorno de producción, usando lógica normal de notificaciones', [
                'environment' => $environment
            ]);
            
            $roles = Permission::whereName('laboratory-purchases.manage.invoices')->sole()->roles;
            
            foreach ($roles as $role) {
                $administrators = Administrator::role($role->name)->get();
                $users = $users->merge($administrators->pluck('user'));
            }
            
            $users = $users->unique('id');
        }

        // 4. ENVIAR NOTIFICACIONES
        // -------------------------
        Log::info('Enviando notificaciones', [
            'total_users' => $users->count(),
            'is_test_environment' => $isTestEnvironment
        ]);
        
        foreach ($users as $user) {
            $user->notify(new LaboratoryPurchaseInvoiceRequested($laboratoryPurchase));
            Log::info('Notificación enviada', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);
        }

        // 5. OBTENER NOMBRE DEL USO CFDI PARA EL MENSAJE FLASH
        // -----------------------------------------------------
        $cfdiUses = config('taxregimes.uses', []);
        $cfdiUseName = $cfdiUses[$request->cfdi_use] ?? $request->cfdi_use;
        
        // 6. REDIRECCIONAR CON MENSAJE DE ÉXITO PERSONALIZADO
        // ----------------------------------------------------
        $message = "Prueba - Se ha solicitado la factura y estará disponible después de 72 horas hábiles. ";
        $message .= "Información del perfil fiscal: RFC: {$taxProfile->rfc}, Uso de CFDI: {$request->cfdi_use} - {$cfdiUseName}";

        // Si es entorno de prueba, agregar información adicional
        if ($isTestEnvironment) {
            $message .= " [Entorno de prueba: {$environment}]";
        }

        Log::info('Redirigiendo con mensaje flash', ['message' => $message]);

        return redirect()->route('laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage($message);
    }
}