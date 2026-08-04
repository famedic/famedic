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
use App\Services\Api\V1\Audit\AppointmentConciergeAuditRecorder;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Support\Api\V1\CheckoutPreparation;
use App\Support\Api\V1\LaboratoryAppointmentSupport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaboratoryAppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentConciergeAuditRecorder $appointmentAudit,
        private readonly LaboratoryAppointmentSupport $appointmentSupport,
    ) {}

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
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));

        $contact = $checkoutPreparation->findOwnedContact($customer, (int) $request->validated('contact_id'));
        if (! $contact) {
            $response = ApiResponse::error(
                'CONTACT_NOT_FOUND',
                'El contacto no fue encontrado.',
                404,
            );
            $classified = $this->appointmentAudit->classifyErrorResponse($response);
            // Cross-user / missing: brand only — never foreign contact_id.
            $this->appointmentAudit->recordAppointmentRequested(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                laboratoryBrand: $brand->value,
                scheduledAt: $scheduledAt,
            );

            return $response;
        }

        $address = $checkoutPreparation->findOwnedAddress($customer, (int) $request->validated('address_id'));
        if (! $address) {
            $response = ApiResponse::error(
                'ADDRESS_NOT_FOUND',
                'La dirección no fue encontrada.',
                404,
            );
            $classified = $this->appointmentAudit->classifyErrorResponse($response);
            // Cross-user / missing: brand only — never foreign address_id.
            $this->appointmentAudit->recordAppointmentRequested(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                laboratoryBrand: $brand->value,
                scheduledAt: $scheduledAt,
            );

            return $response;
        }

        $result = $createAppointmentAction(
            $customer,
            $brand,
            $contact,
            $address,
            $scheduledAt,
            $request->validated('notes'),
        );

        if (isset($result['error'])) {
            $response = match ($result['error']) {
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
            $classified = $this->appointmentAudit->classifyErrorResponse($response);
            $this->appointmentAudit->recordAppointmentRequested(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                laboratoryBrand: $brand->value,
                scheduledAt: $scheduledAt,
            );

            return $response;
        }

        $appointment = $result['appointment'];

        $this->appointmentAudit->recordAppointmentRequested(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 201,
            resourceKey: (string) $appointment->id,
            laboratoryBrand: $brand->value,
            appointmentRowId: (int) $appointment->id,
            appointmentState: AppointmentConciergeAuditRecorder::STATE_PENDING,
            scheduledAt: $scheduledAt,
            checkoutDraftAdvanced: true,
        );

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
            $response = ApiResponse::error(
                'APPOINTMENT_NOT_FOUND',
                'La cita no fue encontrada.',
                404,
            );
            $classified = $this->appointmentAudit->classifyErrorResponse($response);
            // Cross-user / missing: no foreign appointment_id in resource or metadata.
            $this->appointmentAudit->recordAppointmentCancelled(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
            );

            return $response;
        }

        $brand = $appointment->brand->value;
        $previousState = $this->appointmentSupport->resolveStatus($appointment);
        $appointmentRowId = (int) $appointment->id;

        $appointment->delete();

        $this->appointmentAudit->recordAppointmentCancelled(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: (string) $appointmentRowId,
            laboratoryBrand: $brand,
            appointmentRowId: $appointmentRowId,
            previousState: $previousState,
        );

        return ApiResponse::success(['deleted' => true]);
    }
}
