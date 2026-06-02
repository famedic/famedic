<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\LaboratoryAppointments\BuildLaboratoryAppointmentCheckoutProgressAction;
use App\Actions\Admin\LaboratoryAppointments\BuildLaboratoryAppointmentDashboardDataAction;
use App\Actions\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentAction;
use App\Actions\Laboratories\PrepareLaboratoryCheckoutPaymentLinkAction;
use App\Enums\Gender;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryAppointments\DestroyLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\IndexLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\SendLaboratoryAppointmentEmailRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\ShowLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\StoreLaboratoryAppointmentConciergeInteractionRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryStore;
use App\Notifications\LaboratoryAppointmentConfirmedPendingPayment;
use App\Notifications\LaboratoryAppointmentUpdatedByConcierge;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class LaboratoryAppointmentController extends Controller
{
    public function index(
        IndexLaboratoryAppointmentRequest $request,
        BuildLaboratoryAppointmentDashboardDataAction $buildLaboratoryAppointmentDashboardDataAction
    ) {
        $view = $request->get('view', 'list');

        $filterKeys = [
            'search',
            'completed',
            'start_date',
            'end_date',
            'date_range',
            'brand',
            'phone_call_intent',
            'callback_info',
        ];

        $filters = collect($request->only($filterKeys))->filter()->all();
        $filters['view'] = $view;

        $queryFilters = collect($request->only([
            'search',
            'completed',
            'date_range',
            'brand',
            'phone_call_intent',
            'callback_info',
        ]))->filter()->all();

        $dashboard = null;

        if ($view === 'dashboard') {
            $start = $request->filled('start_date')
                ? Carbon::parse($request->start_date, 'America/Monterrey')->startOfDay()
                : null;
            $end = $request->filled('end_date')
                ? Carbon::parse($request->end_date, 'America/Monterrey')->endOfDay()
                : null;
            $dashboard = $buildLaboratoryAppointmentDashboardDataAction($start, $end);

            if (! $request->filled('start_date')) {
                $filters['start_date'] = $dashboard['startDate'];
            }
            if (! $request->filled('end_date')) {
                $filters['end_date'] = $dashboard['endDate'];
            }

            $laboratoryAppointments = new LengthAwarePaginator(
                [],
                0,
                25,
                1,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $laboratoryAppointments = LaboratoryAppointment::with(['customer.user', 'laboratoryStore', 'laboratoryPurchase.transactions'])
                ->filter($queryFilters)->latest()->paginate()->withQueryString();
        }

        return Inertia::render('Admin/LaboratoryAppointments', [
            'laboratoryAppointments' => $laboratoryAppointments,
            'filters' => $filters,
            'dashboard' => $dashboard,
            'brands' => collect(LaboratoryBrand::cases())
                ->map(fn (LaboratoryBrand $brand) => [
                    'value' => $brand->value,
                    'label' => $brand->label(),
                ])->values(),
        ]);
    }

    public function show(
        ShowLaboratoryAppointmentRequest $request,
        LaboratoryAppointment $laboratoryAppointment,
        BuildLaboratoryAppointmentCheckoutProgressAction $buildCheckoutProgress,
    ) {
        $laboratoryAppointment->load([
            'customer.user',
            'laboratoryStore',
            'laboratoryPurchase.laboratoryPurchaseItems',
            'laboratoryPurchase.transactions',
        ]);

        $purchase = $laboratoryAppointment->laboratoryPurchase;

        if ($purchase !== null && $purchase->laboratoryPurchaseItems->isNotEmpty()) {
            $studyItems = $purchase->laboratoryPurchaseItems->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'instructions' => ($item->indications !== null && $item->indications !== '') ? $item->indications : null,
                'requires_appointment' => null,
            ])->values()->all();
            $studyItemsSource = 'purchase';
        } else {
            $laboratoryAppointment->loadMissing([
                'customer.laboratoryCartItems.laboratoryTest',
            ]);
            $cartItems = $laboratoryAppointment->customer?->laboratoryCartItems ?? new Collection;
            $studyItems = $cartItems
                ->filter(fn ($item) => $item->laboratoryTest?->brand?->value === $laboratoryAppointment->brand->value)
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->laboratoryTest?->name ?? 'Estudio',
                    'instructions' => ($item->laboratoryTest?->indications !== null && $item->laboratoryTest?->indications !== '') ? $item->laboratoryTest->indications : null,
                    'requires_appointment' => (bool) ($item->laboratoryTest?->requires_appointment ?? false),
                ])->values()->all();
            $studyItemsSource = 'cart';
        }

        $interactions = $laboratoryAppointment->interactions()
            ->with('adminUser:id,name,email')
            ->latest('id')
            ->get();

        $lastPreferenceInteraction = $interactions
            ->first(fn ($interaction) => $interaction->type === LaboratoryAppointmentInteractionType::PatientCallbackPreference);

        $callbackPreferenceSavedAtFormatted = $lastPreferenceInteraction?->created_at
            ?->timezone('America/Monterrey')
            ?->locale('es')
            ?->isoFormat('dddd D [de] MMMM [de] YYYY, h:mm a');

        $checkoutProgress = $buildCheckoutProgress($laboratoryAppointment);

        return Inertia::render('Admin/LaboratoryAppointment', [
            'laboratoryAppointment' => $laboratoryAppointment,
            'laboratoryStores' => LaboratoryStore::ofBrand($laboratoryAppointment->brand)->orderBy('name')->get(),
            'studyItems' => $studyItems,
            'studyItemsSource' => $studyItemsSource,
            'genders' => Gender::casesWithLabels(),
            'interactions' => $interactions,
            'hasPaidLaboratoryPurchase' => $laboratoryAppointment->hasPaidLaboratoryPurchase(),
            'callbackPreferenceSavedAtFormatted' => $callbackPreferenceSavedAtFormatted,
            'checkoutProgress' => $checkoutProgress,
        ]);
    }

    public function storeInteraction(
        StoreLaboratoryAppointmentConciergeInteractionRequest $request,
        LaboratoryAppointment $laboratoryAppointment
    ) {
        $validated = $request->validated();
        $laboratoryAppointment->interactions()->create([
            'type' => LaboratoryAppointmentInteractionType::from($validated['type']),
            'body' => $validated['body'],
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect()->back()
            ->flashMessage('Interacción registrada en la bitácora.');
    }

    public function update(
        UpdateLaboratoryAppointmentRequest $request,
        LaboratoryAppointment $laboratoryAppointment,
        UpdateLaboratoryAppointmentAction $action,
        PrepareLaboratoryCheckoutPaymentLinkAction $prepareCheckoutPaymentLink,
    ) {
        Log::info('Appointment Debug - Request Data', [
            'appointment_date' => $request->appointment_date,
            'appointment_time' => $request->appointment_time,
            'full_request' => $request->all(),
        ]);

        $action(
            appointment_date: $request->appointment_date,
            appointment_time: $request->appointment_time,
            patient_name: $request->patient_name,
            patient_paternal_lastname: $request->patient_paternal_lastname,
            patient_maternal_lastname: $request->patient_maternal_lastname,
            patient_birth_date: Carbon::parse($request->patient_birth_date),
            patient_gender: Gender::from($request->patient_gender),
            patient_phone: $request->patient_phone,
            patient_phone_country: $request->patient_phone_country,
            laboratory_store: $request->laboratory_store,
            notes: $request->notes,
            laboratoryAppointment: $laboratoryAppointment
        );

        $laboratoryAppointment->refresh();
        $flashMessage = 'Cita actualizada exitosamente.';

        if (! $laboratoryAppointment->hasPaidLaboratoryPurchase()) {
            if ($this->sendPaymentSummaryEmail($laboratoryAppointment, $prepareCheckoutPaymentLink)) {
                $flashMessage = 'Cita actualizada y se envió un correo al cliente para completar el pago.';
            }
        } elseif ($request->boolean('send_notification_email')) {
            if ($this->sendAppointmentInstructionsEmail($laboratoryAppointment)) {
                $flashMessage = 'Cita actualizada y se envió el correo de confirmación al cliente.';
            }
        }

        return redirect()->back()
            ->flashMessage($flashMessage);
    }

    public function sendPaymentSummary(
        SendLaboratoryAppointmentEmailRequest $request,
        LaboratoryAppointment $laboratoryAppointment,
        PrepareLaboratoryCheckoutPaymentLinkAction $prepareCheckoutPaymentLink,
    ) {
        if ($laboratoryAppointment->hasPaidLaboratoryPurchase()) {
            return redirect()->back()
                ->flashMessage('Esta cita ya tiene un pago registrado; no aplica el resumen de pago.');
        }

        if (! $this->sendPaymentSummaryEmail($laboratoryAppointment, $prepareCheckoutPaymentLink)) {
            return redirect()->back()
                ->flashMessage('No se pudo enviar el correo: el cliente no tiene usuario asociado.');
        }

        return redirect()->back()
            ->flashMessage('Se envió el resumen de pago al cliente.');
    }

    public function sendAppointmentInstructions(
        SendLaboratoryAppointmentEmailRequest $request,
        LaboratoryAppointment $laboratoryAppointment,
    ) {
        if (! $laboratoryAppointment->confirmed_at) {
            return redirect()->back()
                ->flashMessage('Primero debes confirmar la cita antes de enviar indicaciones.');
        }

        if (! $laboratoryAppointment->hasPaidLaboratoryPurchase()) {
            return redirect()->back()
                ->flashMessage('El cliente aún no ha pagado; usa «Enviar resumen de pago».');
        }

        if (! $this->sendAppointmentInstructionsEmail($laboratoryAppointment)) {
            return redirect()->back()
                ->flashMessage('No se pudo enviar el correo: el cliente no tiene usuario asociado.');
        }

        return redirect()->back()
            ->flashMessage('Se envió el correo con indicaciones y datos de la cita.');
    }

    private function sendPaymentSummaryEmail(
        LaboratoryAppointment $laboratoryAppointment,
        PrepareLaboratoryCheckoutPaymentLinkAction $prepareCheckoutPaymentLink,
    ): bool {
        $user = $laboratoryAppointment->customer?->user;
        if (! $user) {
            return false;
        }

        $checkoutUrl = $prepareCheckoutPaymentLink($laboratoryAppointment);

        $user->notify(
            new LaboratoryAppointmentConfirmedPendingPayment(
                $laboratoryAppointment,
                $checkoutUrl,
            )
        );

        return true;
    }

    private function sendAppointmentInstructionsEmail(LaboratoryAppointment $laboratoryAppointment): bool
    {
        $user = $laboratoryAppointment->customer?->user;
        if (! $user) {
            return false;
        }

        $laboratoryAppointment->loadMissing([
            'customer.user',
            'laboratoryStore',
            'laboratoryPurchase.transactions',
            'laboratoryPurchase.laboratoryPurchaseItems',
            'customer.laboratoryCartItems.laboratoryTest',
        ]);

        $user->notify(new LaboratoryAppointmentUpdatedByConcierge($laboratoryAppointment));

        return true;
    }

    public function destroy(DestroyLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment)
    {
        $laboratoryAppointment->delete();

        return redirect()->route('admin.laboratory-appointments.index')
            ->flashMessage('Cita eliminada exitosamente.');
    }
}
