<?php

namespace App\Http\Controllers;

use App\Models\ArcoSolicitud;
use App\Http\Requests\ArcoSolicitudes\StoreArcoSolicitudRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class DocumentsServiceController extends Controller
{
    public function termsOfService(Request $request)
    {
        return Inertia::render('Documents/TermsOfService', [
            'name' => 'Términos y condiciones de servicio',
        ]);
    }

    public function privacyPolicy(Request $request)
    {
        return Inertia::render('Documents/PrivacyPolicy', [
            'name' => 'Política de privacidad',
        ]);
    }

    public function rightsARCO(Request $request)
    {
        return Inertia::render('Documents/RightsARCO', [
            'name' => 'Derechos ARCO',
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function storeARCO(StoreArcoSolicitudRequest $request)
    {
        DB::beginTransaction();

        try {
            // Generar folio único
            $folio = ArcoSolicitud::generarFolio();

            // Preparar los derechos ARCO
            $derechos = $request->derechos_arco ?? [];

            // Crear la solicitud
            $solicitud = ArcoSolicitud::create([
                'user_id' => auth()->id(),
                'folio' => $folio,
                'nombre_completo' => $request->nombre_completo,
                'fecha_nacimiento' => $request->fecha_nacimiento,
                'rfc' => $request->rfc,
                'calle' => $request->calle,
                'numero_exterior' => $request->numero_exterior,
                'numero_interior' => $request->numero_interior,
                'colonia' => $request->colonia,
                'municipio_estado' => $request->municipio_estado,
                'codigo_postal' => $request->codigo_postal,
                'telefono_fijo' => $request->telefono_fijo,
                'telefono_celular' => $request->telefono_celular,
                'derecho_acceso' => in_array('acceso', $derechos),
                'derecho_rectificacion' => in_array('rectificacion', $derechos),
                'derecho_cancelacion' => in_array('cancelacion', $derechos),
                'derecho_oposicion' => in_array('oposicion', $derechos),
                'derecho_revocacion' => in_array('revocacion', $derechos),
                'razon_solicitud' => $request->razon_solicitud,
                'solicitado_por' => $request->solicitado_por,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            DB::commit();

            // Redirigir CON LOS DATOS en lugar de usar session flash
            return redirect()->route('rights-arco')->with([
                'success' => [
                    'title' => '¡Solicitud enviada exitosamente!',
                    'message' => 'Tu solicitud ha sido registrada correctamente.',
                    'folio' => $folio, // Esto es lo más importante
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Error al crear solicitud ARCO: ' . $e->getMessage());

            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => 'Ocurrió un error al procesar la solicitud. Por favor, intente nuevamente.']);
        }
    }

    public function successARCO(Request $request)
    {
        return Inertia::render('Documents/ARCOSuccess', [
            'name' => 'Solicitud enviada exitosamente',
            'folio' => session('success.folio') ?? null,
        ]);
    }
}