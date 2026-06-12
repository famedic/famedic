<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\CreateAkubicaLaboratoryAppointmentAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryAppointments\GetAppointmentRequirementsRequest;
use App\Http\Requests\Api\V1\LaboratoryAppointments\ListAppointmentsRequest;
use App\Http\Requests\Api\V1\LaboratoryAppointments\StoreAppointmentRequest;
use App\Http\Resources\Api\V1\LaboratoryAppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\LaboratoryAppointment;
use App\Support\Api\V1\CheckoutPreparation;
use App\Support\Api\V1\LaboratoryAppointmentSupport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaboratoryAppointmentController extends Controller
{
    public function requirements(
        GetAppointmentRequirementsRequest $request,
        LaboratoryAppointmentSupport $appointmentSupport,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));

        return ApiResponse::success(
            $appointmentSupport->requirements($request->user()->customer, $brand, $request),
        );
    }

    public function index(ListAppointmentsRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $query = $customer->laboratoryAppointments()->latest();

        if (! empty($validated['brand'])) {
            $query->where('brand', LaboratoryBrand::from($validated['brand'])->value);
        }

        if (! empty($validated['status'])) {
            match ($validated['status']) {
                'pending' => $query->whereNull('confirmed_at')->whereNull('laboratory_purchase_id'),
                'confirmed' => $query->whereNotNull('confirmed_at')->whereNull('laboratory_purchase_id'),
                'completed' => $query->whereNotNull('laboratory_purchase_id'),
                default => null,
            };
        }

        $paginator = $query->paginate(
            perPage: $validated['per_page'] ?? 20,
            page: $validated['page'] ?? null,
        );

        return ApiResponse::success([
            'appointments' => LaboratoryAppointmentResource::collection($paginator->items())->resolve($request),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(
        StoreAppointmentRequest $request,
        CreateAkubicaLaboratoryAppointmentAction $createAppointmentAction,
        CheckoutPreparation $checkoutPreparation,
    ): JsonResponse {
        $brand = LaboratoryBrand::from($request->validated('brand'));
        $customer = $request->user()->customer;

        $contact = $checkoutPreparation->findOwnedContact($customer, (int) $request->validated('contact_id'));
        if (! $contact) {
            return ApiResponse::error(
                'CONTACT_NOT_FOUND',
                'El contacto no fue encontrado.',
                404,
            );
        }

        $address = $checkoutPreparation->findOwnedAddress($customer, (int) $request->validated('address_id'));
        if (! $address) {
            return ApiResponse::error(
                'ADDRESS_NOT_FOUND',
                'La dirección no fue encontrada.',
                404,
            );
        }

        $result = $createAppointmentAction(
            $customer,
            $brand,
            $contact,
            $address,
            Carbon::parse($request->validated('scheduled_at')),
            $request->validated('notes'),
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'EMPTY_CART' => ApiResponse::error(
                    'EMPTY_CART',
                    'No se puede crear cita con un carrito vacío.',
                    409,
                ),
                'APPOINTMENT_NOT_REQUIRED' => ApiResponse::error(
                    'APPOINTMENT_NOT_REQUIRED',
                    'Ningún estudio del carrito requiere cita.',
                    409,
                ),
                'APPOINTMENT_ALREADY_EXISTS' => ApiResponse::error(
                    'APPOINTMENT_ALREADY_EXISTS',
                    'Ya existe una cita pendiente o confirmada para esta marca.',
                    409,
                ),
                default => ApiResponse::error(
                    'INTERNAL_ERROR',
                    'Ocurrió un error inesperado.',
                    500,
                ),
            };
        }

        $appointment = $result['appointment'];

        return ApiResponse::success([
            'appointment' => array_merge(
                (new LaboratoryAppointmentResource($appointment))->resolve($request),
                [
                    'contact_id' => $contact->id,
                    'address_id' => $address->id,
                    'notes' => $appointment->notes,
                ],
            ),
            'can_continue_to_payment_link' => (bool) $result['can_continue_to_payment_link'],
        ], status: 201);
    }

    public function destroy(Request $request, int $appointmentId): JsonResponse
    {
        $appointment = LaboratoryAppointment::query()
            ->where('id', $appointmentId)
            ->where('customer_id', $request->user()->customer->id)
            ->first();

        if (! $appointment) {
            return ApiResponse::error(
                'APPOINTMENT_NOT_FOUND',
                'La cita no fue encontrada.',
                404,
            );
        }

        $appointment->delete();

        return ApiResponse::success(['deleted' => true]);
    }
}
