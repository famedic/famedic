<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\LaboratoryAppointments\BuildLaboratoryAppointmentDashboardDataAction;
use App\Actions\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentAction;
use App\Enums\Gender;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryAppointments\DestroyLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\IndexLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\ShowLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\StoreLaboratoryAppointmentConciergeInteractionRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryStore;
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

        $filters = collect($request->only([
            'search',
            'completed',
            'start_date',
            'end_date',
        ]))->filter()->all();

        $filters['view'] = $view;

        $queryFilters = collect($request->only([
            'search',
            'completed',
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
        ]);
    }

    public function show(ShowLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment)
    {
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

        return Inertia::render('Admin/LaboratoryAppointment', [
            'laboratoryAppointment' => $laboratoryAppointment,
            'laboratoryStores' => LaboratoryStore::ofBrand($laboratoryAppointment->brand)->orderBy('name')->get(),
            'studyItems' => $studyItems,
            'studyItemsSource' => $studyItemsSource,
            'genders' => Gender::casesWithLabels(),
            'interactions' => $interactions,
            'hasPaidLaboratoryPurchase' => $laboratoryAppointment->hasPaidLaboratoryPurchase(),
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

    public function update(UpdateLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment, UpdateLaboratoryAppointmentAction $action)
    {
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

        if ($request->boolean('send_notification_email') && $laboratoryAppointment->hasPaidLaboratoryPurchase()) {
            $laboratoryAppointment->loadMissing([
                'customer.user',
                'laboratoryStore',
                'laboratoryPurchase.transactions',
                'laboratoryPurchase.laboratoryPurchaseItems',
                'customer.laboratoryCartItems.laboratoryTest',
            ]);

            $laboratoryAppointment->customer?->user?->notify(
                new LaboratoryAppointmentUpdatedByConcierge($laboratoryAppointment)
            );
        }

        return redirect()->back()
            ->flashMessage('Cita actualizada exitosamente.');
    }

    public function destroy(DestroyLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment)
    {
        $laboratoryAppointment->delete();

        return redirect()->route('admin.laboratory-appointments.index')
            ->flashMessage('Cita eliminada exitosamente.');
    }
}
