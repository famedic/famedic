<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryAppointments\IndexLaboratoryAppointmentMetricsRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LaboratoryAppointmentMetricsController extends Controller
{
    public function __invoke(IndexLaboratoryAppointmentMetricsRequest $request)
    {
        $dateRange = $request->input('date_range');
        $explicitStart = $request->input('start_date');
        $explicitEnd = $request->input('end_date');

        [$presetStart, $presetEnd] = $this->resolveDatePreset($dateRange);

        $start = Carbon::parse($explicitStart ?? $presetStart ?? now()->subMonths(2)->format('Y-m-d'))->startOfDay();
        $end = Carbon::parse($explicitEnd ?? $presetEnd ?? now()->format('Y-m-d'))->endOfDay();

        $filters = collect($request->only([
            'completed',
            'brand',
            'phone_call_intent',
            'callback_info',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $appointments = LaboratoryAppointment::query()
            ->whereBetween('created_at', [$start, $end])
            ->filter($filters)
            ->with([
                'laboratoryPurchase' => fn ($q) => $q->select('id', 'brand'),
            ])
            ->get([
                'id',
                'created_at',
                'confirmed_at',
                'appointment_date',
                'laboratory_store_id',
                'brand',
                'laboratory_purchase_id',
                'phone_call_intent_at',
                'callback_availability_starts_at',
                'callback_availability_ends_at',
                'patient_callback_comment',
            ]);

        $appointmentIds = $appointments->pluck('id')->all();

        $idsWithPurchase = $appointments->whereNotNull('laboratory_purchase_id')->pluck('id')->all();

        $catalogByAppointmentId = array_replace(
            array_fill_keys($appointmentIds, 0),
            $this->catalogFamedicFromCartItemsByAppointmentId($appointmentIds)
        );

        $compraByAppointmentId = $this->purchasePriceCentsByAppointmentId($idsWithPurchase);
        $firstPaidAtByAppointmentId = $this->firstPaidAtByAppointmentId($appointmentIds);
        $paidAppointmentIds = array_fill_keys(array_keys($firstPaidAtByAppointmentId), true);

        $requestedVsConfirmed = $this->buildRequestedVsConfirmedSeries(
            $appointments,
            $catalogByAppointmentId,
            $compraByAppointmentId,
            $paidAppointmentIds
        );
        $dailySeries = $this->buildDailySeries($appointments, $catalogByAppointmentId, $compraByAppointmentId, $paidAppointmentIds, $start, $end);
        $monthlyRevenueAndDelta = $this->buildMonthlyRevenueAndDelta($requestedVsConfirmed);

        $byBrand = collect(LaboratoryBrand::cases())->map(function (LaboratoryBrand $brand) use ($appointments, $catalogByAppointmentId, $compraByAppointmentId, $paidAppointmentIds) {
            $subset = $appointments->filter(fn (LaboratoryAppointment $a) => $a->brand === $brand);

            $catalogoSolicitadas = (int) $subset->sum(
                fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
            );
            $catalogoConfirmadas = (int) $subset->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->sum(
                fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
            );
            $compra = (int) $subset->filter(fn (LaboratoryAppointment $a) => isset($paidAppointmentIds[$a->id]))->sum(
                fn (LaboratoryAppointment $a) => (int) ($compraByAppointmentId[$a->id] ?? 0)
            );

            return [
                'brand' => $brand->value,
                'label' => $brand->label(),
                'total' => $subset->count(),
                'confirmadas' => $subset->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->count(),
                'ingresos_cents' => $compra,
                'catalogo_solicitadas_cents' => $catalogoSolicitadas,
                'catalogo_confirmadas_cents' => $catalogoConfirmadas,
                'compra_cents' => $compra,
                'ingresos_cents_solicitudes' => $catalogoSolicitadas,
                'ingresos_cents_confirmadas' => $catalogoConfirmadas,
                'diferencia_ingresos_cents' => $catalogoSolicitadas - $catalogoConfirmadas,
            ];
        })->values();

        $agendadas = $appointments->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a));
        $avgSecondsSolicitudAAgenda = $agendadas->isEmpty()
            ? null
            : $agendadas->avg(fn (LaboratoryAppointment $a) => $a->created_at->diffInSeconds($a->appointment_date));

        $promedioHorasConfirmadas = $avgSecondsSolicitudAAgenda !== null ? round($avgSecondsSolicitudAAgenda / 3600, 2) : null;

        $withIntent = $appointments->whereNotNull('phone_call_intent_at')->count();

        $withCallbackPrefs = $appointments->filter(fn (LaboratoryAppointment $a) => $a->callback_availability_starts_at !== null
            || $a->callback_availability_ends_at !== null
            || filled($a->patient_callback_comment))->count();

        $conCitaProgramada = $appointments->whereNotNull('appointment_date')->count();
        $confirmadasCount = $agendadas->count();
        $comprasConcretadasCount = count($paidAppointmentIds);
        $totalSolicitudes = $appointments->count();

        $pctConfirmadasSobreProgramadas = $conCitaProgramada > 0
            ? round(($confirmadasCount / $conCitaProgramada) * 100, 1)
            : null;

        $tasaConfirmacionSobreSolicitudes = $totalSolicitudes > 0
            ? round(($confirmadasCount / $totalSolicitudes) * 100, 1)
            : null;

        $tasaCompraSobreAgendadas = $confirmadasCount > 0
            ? round(($comprasConcretadasCount / $confirmadasCount) * 100, 1)
            : null;

        $catalogoCentsSolicitadas = (int) collect($catalogByAppointmentId)->sum();
        $catalogoCentsConfirmadas = (int) $agendadas->sum(
            fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
        );
        $compraCentsTotal = (int) collect($paidAppointmentIds)->keys()->sum(
            fn (int $id) => (int) ($compraByAppointmentId[$id] ?? 0)
        );

        $pctCatalogoConfirmadasVsSolicitadas = $catalogoCentsSolicitadas > 0
            ? round(($catalogoCentsConfirmadas / $catalogoCentsSolicitadas) * 100, 1)
            : null;
        $pctCompraVsCatalogoSolicitadas = $catalogoCentsSolicitadas > 0
            ? round(($compraCentsTotal / $catalogoCentsSolicitadas) * 100, 1)
            : null;
        $pctCompraVsCatalogoConfirmadas = $catalogoCentsConfirmadas > 0
            ? round(($compraCentsTotal / $catalogoCentsConfirmadas) * 100, 1)
            : null;

        $promedioHorasSolicitudAPago = $this->averageHoursRequestToPayment($appointments, $firstPaidAtByAppointmentId);
        $promedioHorasAgendaAPago = $this->averageHoursAgendaToPayment($appointments, $firstPaidAtByAppointmentId);

        $byStudyName = $this->aggregateMetricsByStudyName($appointmentIds, $paidAppointmentIds);
        $byCategory = $this->aggregateMetricsByCategory($appointmentIds, $paidAppointmentIds);

        $citasCatalogoMayorCero = $appointments->filter(
            fn (LaboratoryAppointment $a) => ($catalogByAppointmentId[$a->id] ?? 0) > 0
        )->count();
        $citasConCompra = $appointments->whereNotNull('laboratory_purchase_id')->count();
        $citasPagoRegistrado = $comprasConcretadasCount;

        return Inertia::render('Admin/LaboratoryAppointmentMetrics', [
            'filters' => [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'date_range' => $dateRange ?? '',
                'completed' => $filters['completed'] ?? '',
                'brand' => $filters['brand'] ?? '',
                'phone_call_intent' => $filters['phone_call_intent'] ?? '',
                'callback_info' => $filters['callback_info'] ?? '',
            ],
            'brands' => collect(LaboratoryBrand::cases())
                ->map(fn (LaboratoryBrand $brand) => [
                    'value' => $brand->value,
                    'label' => $brand->label(),
                ])->values(),
            'summary' => [
                'total_solicitudes' => $totalSolicitudes,
                'total_confirmadas' => $confirmadasCount,
                'total_compras_concretadas' => $comprasConcretadasCount,
                'catalogo_cents_solicitadas' => $catalogoCentsSolicitadas,
                'catalogo_cents_confirmadas' => $catalogoCentsConfirmadas,
                'catalogo_cents_pendientes' => max(0, $catalogoCentsSolicitadas - $catalogoCentsConfirmadas),
                'compra_cents_total' => $compraCentsTotal,
                'ingresos_cents_solicitudes' => $catalogoCentsSolicitadas,
                'ingresos_cents_confirmadas' => $catalogoCentsConfirmadas,
                'ingresos_cents_no_confirmadas_con_compra' => max(
                    0,
                    $catalogoCentsSolicitadas - $catalogoCentsConfirmadas
                ),
                'con_cita_programada' => $conCitaProgramada,
                'pct_confirmadas_sobre_programadas' => $pctConfirmadasSobreProgramadas,
                'tasa_confirmacion_sobre_solicitudes' => $tasaConfirmacionSobreSolicitudes,
                'tasa_compra_sobre_confirmadas' => $tasaCompraSobreAgendadas,
                'pct_catalogo_confirmadas_vs_solicitadas' => $pctCatalogoConfirmadasVsSolicitadas,
                'pct_compra_vs_catalogo_solicitadas' => $pctCompraVsCatalogoSolicitadas,
                'pct_compra_vs_catalogo_confirmadas' => $pctCompraVsCatalogoConfirmadas,
                'promedio_horas_solicitud_confirmacion_confirmadas' => $promedioHorasConfirmadas,
                'promedio_horas_solicitud_a_pago' => $promedioHorasSolicitudAPago,
                'promedio_horas_agenda_a_pago' => $promedioHorasAgendaAPago,
                'pacientes_con_intento_llamada' => $withIntent,
                'pacientes_con_disponibilidad_o_comentario' => $withCallbackPrefs,
            ],
            'requestedVsConfirmed' => $requestedVsConfirmed,
            'dailySeries' => $dailySeries,
            'monthlyRevenueAndDelta' => $monthlyRevenueAndDelta,
            'byBrand' => $byBrand,
            'byStudyName' => $byStudyName,
            'byCategory' => $byCategory,
            'desgloses' => [
                'totales' => [
                    'citas_solicitadas' => $totalSolicitudes,
                    'citas_confirmadas' => $confirmadasCount,
                    'citas_pendientes_confirmar' => max(0, $totalSolicitudes - $confirmadasCount),
                    'citas_con_compra' => $citasConCompra,
                    'citas_con_monto_catalogo' => $citasCatalogoMayorCero,
                    'citas_con_pago_registrado' => $citasPagoRegistrado,
                    'catalogo_cents_solicitadas' => $catalogoCentsSolicitadas,
                    'catalogo_cents_confirmadas' => $catalogoCentsConfirmadas,
                    'catalogo_cents_diferencia_solicitadas_menos_confirmadas' => $catalogoCentsSolicitadas - $catalogoCentsConfirmadas,
                    'compra_cents_total' => $compraCentsTotal,
                    'compra_cents_solo_pedidos_sin_pago' => max(
                        0,
                        (int) collect($compraByAppointmentId)->sum() - $compraCentsTotal
                    ),
                ],
            ],
        ]);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveDatePreset(?string $dateRange): array
    {
        return match ($dateRange) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'last_7_days' => [now()->subDays(7)->toDateString(), now()->toDateString()],
            'last_6_months' => [now()->subMonths(6)->toDateString(), now()->toDateString()],
            default => [null, null],
        };
    }

    /**
     * Catálogo Famedic por cita desde ítems del carrito del paciente (`laboratory_cart_items` + `laboratory_tests`),
     * solo estudios con cita y misma marca que la solicitud.
     *
     * @return array<int, int>
     */
    private function catalogFamedicFromCartItemsByAppointmentId(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $rows = DB::table('laboratory_appointments as la')
            ->join('laboratory_cart_items as lci', 'lci.customer_id', '=', 'la.customer_id')
            ->join('laboratory_tests as lt', 'lt.id', '=', 'lci.laboratory_test_id')
            ->whereIn('la.id', $appointmentIds)
            ->whereNull('la.deleted_at')
            ->whereNull('lci.deleted_at')
            ->where('lt.requires_appointment', true)
            ->whereColumn('lt.brand', 'la.brand')
            ->groupBy('la.id')
            ->select('la.id', DB::raw('SUM(COALESCE(lt.famedic_price_cents, 0)) as revenue_cents'));

        return $rows->pluck('revenue_cents', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Suma de precios cobrados en ítems de compra (`laboratory_purchase_items.price_cents`) por cita.
     *
     * @return array<int, int>
     */
    private function purchasePriceCentsByAppointmentId(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $rows = DB::table('laboratory_appointments as la')
            ->join('laboratory_purchases as lp', 'la.laboratory_purchase_id', '=', 'lp.id')
            ->join('laboratory_purchase_items as lpi', 'lpi.laboratory_purchase_id', '=', 'lp.id')
            ->whereIn('la.id', $appointmentIds)
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereNull('la.deleted_at')
            ->groupBy('la.id')
            ->select('la.id', DB::raw('SUM(lpi.price_cents) as cents'));

        return $rows->pluck('cents', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Cita confirmada operativamente: fecha/hora de cita y sucursal asignadas.
     */
    private function isCitaAgendada(LaboratoryAppointment $a): bool
    {
        return $a->appointment_date !== null && $a->laboratory_store_id !== null;
    }

    /**
     * Primer momento de cobro por cita vía pivote `transactionables` (misma lógica que consultas SQL directas).
     *
     * @param  array<int>  $appointmentIds
     * @return array<int, Carbon>
     */
    private function firstPaidAtByAppointmentId(array $appointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $purchaseType = LaboratoryPurchase::class;

        $rows = DB::table('laboratory_appointments as la')
            ->join('laboratory_purchases as lp', 'la.laboratory_purchase_id', '=', 'lp.id')
            ->join('transactionables as ta', function ($join) use ($purchaseType) {
                $join->on('ta.transactionable_id', '=', 'lp.id')
                    ->where('ta.transactionable_type', '=', $purchaseType);
            })
            ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
            ->whereIn('la.id', $appointmentIds)
            ->whereNull('la.deleted_at')
            ->whereNull('lp.deleted_at')
            ->where(function ($q) {
                $q->whereNull('t.payment_status')
                    ->orWhere('t.payment_status', '<>', 'failed');
            })
            ->whereRaw('COALESCE(t.gateway_processed_at, t.created_at) IS NOT NULL')
            ->groupBy('la.id')
            ->select([
                'la.id',
                DB::raw('MIN(COALESCE(t.gateway_processed_at, t.created_at)) as first_paid_at'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = Carbon::parse($row->first_paid_at);
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryAppointment>  $appointments
     * @param  array<int, int>  $catalogByAppointmentId
     * @param  array<int, int>  $compraByAppointmentId
     * @return array<int, array<string, mixed>>
     */
    private function buildRequestedVsConfirmedSeries(
        $appointments,
        array $catalogByAppointmentId,
        array $compraByAppointmentId,
        array $paidAppointmentIds
    ): array {
        return $appointments
            ->groupBy(fn (LaboratoryAppointment $a) => $a->created_at->format('Y-m'))
            ->map(function ($group, string $ym) use ($catalogByAppointmentId, $compraByAppointmentId, $paidAppointmentIds) {
                $c = Carbon::parse($ym.'-01');

                $catalogoSolicitadas = (int) $group->sum(
                    fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
                );
                $catalogoConfirmadas = (int) $group->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->sum(
                    fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
                );
                $compra = (int) $group->filter(fn (LaboratoryAppointment $a) => isset($paidAppointmentIds[$a->id]))->sum(
                    fn (LaboratoryAppointment $a) => (int) ($compraByAppointmentId[$a->id] ?? 0)
                );
                $confirmadasCount = $group->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->count();

                return [
                    'period' => $ym,
                    'label' => $c->locale('es')->isoFormat('MMM YYYY'),
                    'solicitudes' => $group->count(),
                    'confirmadas' => $confirmadasCount,
                    'ingresos_cents' => $compra,
                    'ingresos_catalogo_solicitadas_cents' => $catalogoSolicitadas,
                    'ingresos_catalogo_confirmadas_cents' => $catalogoConfirmadas,
                    'ingresos_compra_cents' => $compra,
                    'ingresos_cents_solicitudes' => $catalogoSolicitadas,
                    'ingresos_cents_confirmadas' => $catalogoConfirmadas,
                    'diferencia_ingresos_cents' => $catalogoSolicitadas - $catalogoConfirmadas,
                    'diferencia_solicitudes_confirmadas' => $group->count() - $confirmadasCount,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $monthlyRows
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlyRevenueAndDelta(array $monthlyRows): array
    {
        $prevIngresos = null;

        return collect($monthlyRows)
            ->map(function (array $row) use (&$prevIngresos) {
                $ingresos = (int) ($row['ingresos_compra_cents'] ?? $row['ingresos_cents'] ?? 0);
                $delta = $prevIngresos === null ? null : ($ingresos - $prevIngresos);
                $deltaPct = ($prevIngresos !== null && $prevIngresos > 0)
                    ? round((($ingresos - $prevIngresos) / $prevIngresos) * 100, 1)
                    : null;
                $prevIngresos = $ingresos;

                return [
                    'period' => $row['period'],
                    'label' => $row['label'],
                    'ingresos_cents' => $ingresos,
                    'ingresos_compra_cents' => $ingresos,
                    'variacion_mes_anterior_cents' => $delta,
                    'variacion_mes_anterior_pct' => $deltaPct,
                ];
            })
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryAppointment>  $appointments
     * @param  array<int, int>  $catalogByAppointmentId
     * @param  array<int, int>  $compraByAppointmentId
     * @param  array<int, true>  $paidAppointmentIds
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySeries(
        $appointments,
        array $catalogByAppointmentId,
        array $compraByAppointmentId,
        array $paidAppointmentIds,
        Carbon $start,
        Carbon $end
    ): array {
        $byDate = $appointments->groupBy(fn (LaboratoryAppointment $a) => $a->created_at->format('Y-m-d'));
        $rows = [];
        $cursor = $start->copy()->startOfDay();
        $limit = $end->copy()->startOfDay();

        while ($cursor->lte($limit)) {
            $key = $cursor->format('Y-m-d');
            $group = $byDate->get($key, collect());

            $catalogoSolicitadas = (int) $group->sum(
                fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
            );
            $catalogoConfirmadas = (int) $group->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->sum(
                fn (LaboratoryAppointment $a) => (int) ($catalogByAppointmentId[$a->id] ?? 0)
            );
            $compra = (int) $group->filter(fn (LaboratoryAppointment $a) => isset($paidAppointmentIds[$a->id]))->sum(
                fn (LaboratoryAppointment $a) => (int) ($compraByAppointmentId[$a->id] ?? 0)
            );
            $logradas = (int) $group->filter(fn (LaboratoryAppointment $a) => isset($paidAppointmentIds[$a->id]))->count();

            $rows[] = [
                'date' => $key,
                'label' => $cursor->locale('es')->isoFormat('D MMM'),
                'solicitudes' => $group->count(),
                'confirmadas' => $group->filter(fn (LaboratoryAppointment $a) => $this->isCitaAgendada($a))->count(),
                'logradas' => $logradas,
                'intentos_llamada' => $group->whereNotNull('phone_call_intent_at')->count(),
                'catalogo_solicitadas_cents' => $catalogoSolicitadas,
                'catalogo_confirmadas_cents' => $catalogoConfirmadas,
                'compra_cents' => $compra,
            ];

            $cursor->addDay();
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryAppointment>  $appointments
     * @param  array<int, Carbon>  $firstPaidAtByAppointmentId
     */
    private function averageHoursRequestToPayment($appointments, array $firstPaidAtByAppointmentId): ?float
    {
        $seconds = [];

        foreach ($appointments as $a) {
            $paidAt = $firstPaidAtByAppointmentId[$a->id] ?? null;
            if (! $paidAt) {
                continue;
            }

            $seconds[] = $a->created_at->diffInSeconds($paidAt);
        }

        if ($seconds === []) {
            return null;
        }

        return round(array_sum($seconds) / count($seconds) / 3600, 2);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryAppointment>  $appointments
     * @param  array<int, Carbon>  $firstPaidAtByAppointmentId
     */
    private function averageHoursAgendaToPayment($appointments, array $firstPaidAtByAppointmentId): ?float
    {
        $seconds = [];

        foreach ($appointments as $a) {
            if (! $this->isCitaAgendada($a)) {
                continue;
            }

            $paidAt = $firstPaidAtByAppointmentId[$a->id] ?? null;
            if (! $paidAt) {
                continue;
            }

            $seconds[] = $a->appointment_date->diffInSeconds($paidAt);
        }

        if ($seconds === []) {
            return null;
        }

        return round(array_sum($seconds) / count($seconds) / 3600, 2);
    }

    /**
     * Líneas de precio cobrado en pedido solo para citas con pago registrado (para desglose por estudio).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function purchaseCompraLinesForPaidAppointments(array $paidAppointmentIds)
    {
        if ($paidAppointmentIds === []) {
            return collect();
        }

        $ids = array_keys($paidAppointmentIds);

        return DB::table('laboratory_purchase_items as lpi')
            ->join('laboratory_purchases as lp', 'lpi.laboratory_purchase_id', '=', 'lp.id')
            ->join('laboratory_appointments as la', 'la.laboratory_purchase_id', '=', 'lp.id')
            ->whereIn('la.id', $ids)
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereNull('la.deleted_at')
            ->leftJoin('laboratory_tests as lt', function ($join) {
                $join->on('lt.gda_id', '=', 'lpi.gda_id')
                    ->on('lt.brand', '=', 'lp.brand');
            })
            ->select([
                'la.id',
                DB::raw('COALESCE(lt.name, lpi.name) as label'),
                'lpi.price_cents as compra_cents',
            ])
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateMetricsByStudyName(array $appointmentIds, array $paidAppointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $cartLines = DB::table('laboratory_appointments as la')
            ->join('laboratory_cart_items as lci', 'lci.customer_id', '=', 'la.customer_id')
            ->join('laboratory_tests as lt', 'lt.id', '=', 'lci.laboratory_test_id')
            ->whereIn('la.id', $appointmentIds)
            ->whereNull('la.deleted_at')
            ->whereNull('lci.deleted_at')
            ->where('lt.requires_appointment', true)
            ->whereColumn('lt.brand', 'la.brand')
            ->select([
                'la.id',
                'la.appointment_date',
                'la.laboratory_store_id',
                DB::raw('COALESCE(lt.name, \'Sin nombre\') as label'),
                DB::raw('COALESCE(lt.famedic_price_cents, 0) as famedic_cents'),
            ])
            ->get();

        $compraLines = $this->purchaseCompraLinesForPaidAppointments($paidAppointmentIds);

        $byLabel = [];

        foreach ($cartLines as $row) {
            $label = filled($row->label) ? $row->label : 'Sin nombre';
            if (! isset($byLabel[$label])) {
                $byLabel[$label] = [
                    'label' => $label,
                    'catalogo_solicitadas_cents' => 0,
                    'catalogo_confirmadas_cents' => 0,
                    'compra_cents' => 0,
                    'cantidad_lineas' => 0,
                ];
            }

            $f = (int) $row->famedic_cents;
            $byLabel[$label]['catalogo_solicitadas_cents'] += $f;
            if ($row->appointment_date !== null && $row->laboratory_store_id !== null) {
                $byLabel[$label]['catalogo_confirmadas_cents'] += $f;
            }
            $byLabel[$label]['cantidad_lineas']++;
        }

        foreach ($compraLines as $row) {
            $label = filled($row->label) ? $row->label : 'Sin nombre';
            if (! isset($byLabel[$label])) {
                $byLabel[$label] = [
                    'label' => $label,
                    'catalogo_solicitadas_cents' => 0,
                    'catalogo_confirmadas_cents' => 0,
                    'compra_cents' => 0,
                    'cantidad_lineas' => 0,
                ];
            }
            $byLabel[$label]['compra_cents'] += (int) $row->compra_cents;
            $byLabel[$label]['cantidad_lineas']++;
        }

        return collect($byLabel)
            ->sortByDesc(fn (array $r) => max($r['catalogo_solicitadas_cents'], $r['compra_cents']))
            ->take(18)
            ->values()
            ->map(fn (array $r) => $r + ['venta_cents' => $r['catalogo_solicitadas_cents']])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateMetricsByCategory(array $appointmentIds, array $paidAppointmentIds): array
    {
        if ($appointmentIds === []) {
            return [];
        }

        $cartCat = DB::table('laboratory_appointments as la')
            ->join('laboratory_cart_items as lci', 'lci.customer_id', '=', 'la.customer_id')
            ->join('laboratory_tests as lt', 'lt.id', '=', 'lci.laboratory_test_id')
            ->whereIn('la.id', $appointmentIds)
            ->whereNull('la.deleted_at')
            ->whereNull('lci.deleted_at')
            ->where('lt.requires_appointment', true)
            ->whereColumn('lt.brand', 'la.brand')
            ->leftJoin('laboratory_test_categories as ltc', 'lt.laboratory_test_category_id', '=', 'ltc.id')
            ->select([
                'la.id',
                'la.appointment_date',
                'la.laboratory_store_id',
                'ltc.name as category_name',
                DB::raw('COALESCE(lt.famedic_price_cents, 0) as famedic_cents'),
            ])
            ->get();

        $purchaseCat = collect();
        if ($paidAppointmentIds !== []) {
            $purchaseCat = DB::table('laboratory_purchase_items as lpi')
                ->join('laboratory_purchases as lp', 'lpi.laboratory_purchase_id', '=', 'lp.id')
                ->join('laboratory_appointments as la', 'la.laboratory_purchase_id', '=', 'lp.id')
                ->whereIn('la.id', array_keys($paidAppointmentIds))
                ->whereNull('lpi.deleted_at')
                ->whereNull('lp.deleted_at')
                ->whereNull('la.deleted_at')
                ->leftJoin('laboratory_tests as lt', function ($join) {
                    $join->on('lt.gda_id', '=', 'lpi.gda_id')
                        ->on('lt.brand', '=', 'lp.brand');
                })
                ->leftJoin('laboratory_test_categories as ltc', 'lt.laboratory_test_category_id', '=', 'ltc.id')
                ->select([
                    'ltc.name as category_name',
                    'lpi.price_cents as compra_cents',
                ])
                ->get();
        }

        $byCat = [];

        foreach ($cartCat as $row) {
            $label = filled($row->category_name) ? $row->category_name : 'Sin categoría en catálogo';
            if (! isset($byCat[$label])) {
                $byCat[$label] = [
                    'label' => $label,
                    'catalogo_solicitadas_cents' => 0,
                    'catalogo_confirmadas_cents' => 0,
                    'compra_cents' => 0,
                    'cantidad_lineas' => 0,
                ];
            }

            $f = (int) $row->famedic_cents;
            $byCat[$label]['catalogo_solicitadas_cents'] += $f;
            if ($row->appointment_date !== null && $row->laboratory_store_id !== null) {
                $byCat[$label]['catalogo_confirmadas_cents'] += $f;
            }
            $byCat[$label]['cantidad_lineas']++;
        }

        foreach ($purchaseCat as $row) {
            $label = filled($row->category_name) ? $row->category_name : 'Sin categoría en catálogo';
            if (! isset($byCat[$label])) {
                $byCat[$label] = [
                    'label' => $label,
                    'catalogo_solicitadas_cents' => 0,
                    'catalogo_confirmadas_cents' => 0,
                    'compra_cents' => 0,
                    'cantidad_lineas' => 0,
                ];
            }
            $byCat[$label]['compra_cents'] += (int) $row->compra_cents;
            $byCat[$label]['cantidad_lineas']++;
        }

        return collect($byCat)
            ->sortByDesc(fn (array $r) => max($r['catalogo_solicitadas_cents'], $r['compra_cents']))
            ->values()
            ->map(fn (array $r) => $r + ['venta_cents' => $r['catalogo_solicitadas_cents']])
            ->all();
    }
}
