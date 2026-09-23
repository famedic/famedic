<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\ExtractTaxProfileFromConstanciaAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\ExtractCustomerTaxProfileDataRequest;
use App\Http\Requests\Admin\Customers\StoreCustomerTaxProfileRequest;
use App\Http\Requests\Admin\Customers\UpdateCustomerTaxProfileRequest;
use App\Models\Customer;
use App\Models\TaxProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CustomerTaxProfileController extends Controller
{
    public function extractData(
        ExtractCustomerTaxProfileDataRequest $request,
        Customer $customer,
        ExtractTaxProfileFromConstanciaAction $action,
    ): JsonResponse {
        $file = $request->file('fiscal_certificate');

        Log::info('Extracción de constancia (admin) para cliente', [
            'operation' => 'admin_customer_tax_profile_extract',
            'admin_user_id' => $request->user()->id,
            'customer_id' => $customer->id,
            'mime_type' => $file?->getMimeType(),
            'size_bytes' => $file?->getSize(),
        ]);

        try {
            $result = $action($file);

            return response()->json([
                'success' => true,
                'message' => 'Datos extraídos correctamente. Revisa y confirma antes de guardar.',
                'data' => $result->toHttpData(),
            ]);
        } catch (ConstanciaExtractionException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->publicMessage(),
                'data' => null,
            ], $e->status);
        } catch (\Throwable $e) {
            Log::error('Error inesperado en extracción admin de constancia', [
                'operation' => 'admin_customer_tax_profile_extract',
                'admin_user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'code' => ConstanciaExtractionException::EXTRACTION_FAILED,
                'message' => 'No pudimos procesar la constancia. Intenta de nuevo o captura los datos manualmente.',
                'data' => null,
            ], 422);
        }
    }

    public function store(
        StoreCustomerTaxProfileRequest $request,
        Customer $customer,
        CreateTaxProfileAction $action,
    ): JsonResponse {
        try {
            $extractedData = $request->decodedExtractedData();

            $taxProfile = $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use ?? 'G03',
                fiscalCertificate: $request->file('fiscal_certificate'),
                extractedData: $extractedData,
                customerId: $customer->id,
            );

            Log::info('Perfil fiscal creado por admin para cliente', [
                'operation' => 'admin_customer_tax_profile_store',
                'admin_user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'tax_profile_id' => $taxProfile->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Perfil fiscal creado exitosamente para el paciente.',
                'data' => [
                    'id' => $taxProfile->id,
                    'name' => $taxProfile->name,
                    'rfc' => $taxProfile->rfc,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error al crear perfil fiscal (admin)', [
                'operation' => 'admin_customer_tax_profile_store',
                'admin_user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el perfil fiscal.',
            ], 500);
        }
    }

    public function update(
        UpdateCustomerTaxProfileRequest $request,
        Customer $customer,
        TaxProfile $tax_profile,
        UpdateTaxProfileAction $action,
    ): JsonResponse {
        try {
            $extractedData = $request->decodedExtractedData();

            $taxProfile = $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use,
                taxProfile: $tax_profile,
                fiscalCertificate: $request->hasFile('fiscal_certificate')
                    ? $request->file('fiscal_certificate')
                    : null,
                extractedData: $extractedData,
            );

            Log::info('Perfil fiscal actualizado por admin para cliente', [
                'operation' => 'admin_customer_tax_profile_update',
                'admin_user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'tax_profile_id' => $taxProfile->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Perfil fiscal actualizado exitosamente.',
                'data' => [
                    'id' => $taxProfile->id,
                    'name' => $taxProfile->name,
                    'rfc' => $taxProfile->rfc,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error al actualizar perfil fiscal (admin)', [
                'operation' => 'admin_customer_tax_profile_update',
                'admin_user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'tax_profile_id' => $tax_profile->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el perfil fiscal.',
            ], 500);
        }
    }
}
