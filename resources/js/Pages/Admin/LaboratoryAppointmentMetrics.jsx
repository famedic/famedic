import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	ArchiveBoxIcon,
	CalendarDaysIcon,
	CheckCircleIcon,
	ClockIcon,
	PhoneIcon,
	ChatBubbleLeftRightIcon,
	AdjustmentsHorizontalIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Tab, TabGroup, TabList } from "@/Components/Catalyst/tabs";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
	Line,
	ComposedChart,
	LabelList,
	PieChart,
	Pie,
	Cell,
} from "recharts";

/** Ejes y leyenda Recharts: los ticks SVG usan `recharts-cartesian-axis-tick-value` (fill fijo ~#666). */
const chartUi =
	"text-zinc-600 dark:text-zinc-400 [&_.recharts-cartesian-axis-tick-value]:!fill-zinc-600 dark:[&_.recharts-cartesian-axis-tick-value]:!fill-zinc-400 [&_.recharts-legend-item-text]:!text-zinc-700 dark:[&_.recharts-legend-item-text]:!text-zinc-300";

function formatMxnFromCents(cents) {
	if (cents == null || Number.isNaN(Number(cents))) {
		return "—";
	}
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
		minimumFractionDigits: 0,
		maximumFractionDigits: 0,
	}).format(Number(cents) / 100);
}

function formatHours(h) {
	if (h == null || Number.isNaN(Number(h))) {
		return "—";
	}
	return `${Number(h).toLocaleString("es-MX", {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	})} h`;
}

/** Promedio en horas; si es menor a 1 h se muestra en minutos. */
function formatAvgHoursOrMinutes(h) {
	if (h == null || Number.isNaN(Number(h))) {
		return "—";
	}
	const n = Number(h);
	if (n < 1) {
		return `${Math.round(n * 60)} min`;
	}
	return formatHours(n);
}

function CardKpi({ title, children, hint }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">{title}</Text>
			<div className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
				{children}
			</div>
			{hint ? (
				<Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{hint}</Text>
			) : null}
		</div>
	);
}

function RechartsTooltipCard({ title, rows }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-black/5 dark:border-zinc-600 dark:bg-zinc-950 dark:ring-white/10">
			{title ? (
				<p className="mb-1.5 font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</p>
			) : null}
			<ul className="space-y-1">
				{rows.map((r) => (
					<li
						key={r.label}
						className="flex justify-between gap-6 text-zinc-600 dark:text-zinc-300"
					>
						<span>{r.label}</span>
						<span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-100">
							{r.value}
						</span>
					</li>
				))}
			</ul>
		</div>
	);
}

function SolicitudesTooltip({ active, payload, label }) {
	if (!active || !payload?.length) {
		return null;
	}
	const p = payload.reduce((acc, cur) => {
		acc[cur.dataKey] = cur.value;
		return acc;
	}, {});
	const catSol = p.ingresos_catalogo_solicitadas_cents ?? p.ingresos_cents_solicitudes;
	const catConf =
		p.ingresos_catalogo_confirmadas_cents ?? p.ingresos_cents_confirmadas;
	const compra = p.ingresos_compra_cents;
	const pend =
		p.diferencia_solicitudes_confirmadas ?? p.pendientes_de_confirmar;

	return (
		<RechartsTooltipCard
			title={label}
			rows={[
				{ label: "Solicitudes", value: p.solicitudes ?? "—" },
				{ label: "Agendadas (fecha + sucursal)", value: p.confirmadas ?? "—" },
				{
					label: "Pendientes de agendar",
					value: pend != null ? pend : "—",
				},
				{
					label: "$ Catálogo (solicitadas)",
					value: formatMxnFromCents(catSol),
				},
				{
					label: "$ Catálogo (agendadas)",
					value: formatMxnFromCents(catConf),
				},
				{
					label: "$ Compra (solo con pago registrado)",
					value: formatMxnFromCents(compra),
				},
				{
					label: "Catálogo pendiente $ (solic. − conf.)",
					value: formatMxnFromCents(p.diferencia_ingresos_cents),
				},
			]}
		/>
	);
}

function IngresosVariacionTooltip({ active, payload, label }) {
	if (!active || !payload?.length) {
		return null;
	}
	const row = payload[0]?.payload;
	if (!row) {
		return null;
	}
	return (
		<RechartsTooltipCard
			title={label}
			rows={[
				{
					label: "Venta real del mes (suma ítems de compra)",
					value: formatMxnFromCents(row.ingresos_cents),
				},
				{
					label: "Variación vs mes anterior",
					value:
						row.variacion_mes_anterior_cents == null
							? "—"
							: formatMxnFromCents(row.variacion_mes_anterior_cents),
				},
				{
					label: "Variación % vs mes anterior",
					value:
						row.variacion_mes_anterior_pct == null
							? "—"
							: `${row.variacion_mes_anterior_pct} %`,
				},
			]}
		/>
	);
}

function VentaBarTooltip({ active, payload }) {
	if (!active || !payload?.length) {
		return null;
	}
	const row = payload[0]?.payload;
	if (!row) {
		return null;
	}
	return (
		<RechartsTooltipCard
			title={row.label}
			rows={[
				{
					label: "$ Catálogo (solicitadas)",
					value: formatMxnFromCents(
						row.catalogo_solicitadas_cents ?? row.venta_cents,
					),
				},
				{
					label: "$ Catálogo (agendadas)",
					value: formatMxnFromCents(row.catalogo_confirmadas_cents),
				},
				{
					label: "$ Compra (con pago)",
					value: formatMxnFromCents(row.compra_cents),
				},
				{ label: "Líneas", value: row.cantidad_lineas ?? row.cantidad_items ?? "—" },
			]}
		/>
	);
}

function BrandTooltip({ active, payload, label }) {
	if (!active || !payload?.length) {
		return null;
	}
	const row = payload[0]?.payload;
	if (!row) {
		return null;
	}
	return (
		<RechartsTooltipCard
			title={label}
			rows={[
				{ label: "Solicitudes", value: row.total ?? "—" },
				{ label: "Agendadas (fecha+sucursal)", value: row.confirmadas ?? "—" },
				{
					label: "$ Catálogo (solicitadas)",
					value: formatMxnFromCents(row.catalogo_solicitadas_cents),
				},
				{
					label: "$ Catálogo (agendadas)",
					value: formatMxnFromCents(row.catalogo_confirmadas_cents),
				},
				{
					label: "$ Compra (con pago)",
					value: formatMxnFromCents(row.compra_cents),
				},
			]}
		/>
	);
}

function DailyActivityTooltip({ active, payload, label }) {
	if (!active || !payload?.length) {
		return null;
	}
	const row = payload[0]?.payload;
	if (!row) {
		return null;
	}

	return (
		<RechartsTooltipCard
			title={label}
			rows={[
				{ label: "Solicitadas", value: row.solicitudes ?? "—" },
				{ label: "Agendadas", value: row.confirmadas ?? "—" },
				{ label: "Compras concretadas (pago)", value: row.logradas ?? "—" },
				{ label: "Intentos de llamada", value: row.intentos_llamada ?? "—" },
				{
					label: "$ Catálogo (solicitadas)",
					value: formatMxnFromCents(row.catalogo_solicitadas_cents),
				},
				{
					label: "$ Catálogo (agendadas)",
					value: formatMxnFromCents(row.catalogo_confirmadas_cents),
				},
				{
					label: "$ Compra (con pago)",
					value: formatMxnFromCents(row.compra_cents),
				},
			]}
		/>
	);
}

const tableShell =
	"w-full text-sm border-collapse overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700";

const tableHead = "bg-zinc-50 text-left text-xs font-semibold text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-300";

const tableCell = "border-t border-zinc-200 px-3 py-2 text-zinc-800 dark:border-zinc-700 dark:text-zinc-200";

const tableCellNum = `${tableCell} text-right tabular-nums`;

export default function LaboratoryAppointmentMetrics({
	filters,
	brands,
	summary,
	requestedVsConfirmed,
	dailySeries,
	monthlyRevenueAndDelta,
	byBrand,
	byStudyName,
	byCategory,
	desgloses,
}) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date,
		end_date: filters.end_date,
		date_range: filters.date_range || "",
		completed: filters.completed || "",
		brand: filters.brand || "",
		phone_call_intent: filters.phone_call_intent || "",
		callback_info: filters.callback_info || "",
	});
	const [showFilters, setShowFilters] = useState(false);
	const [activeTab, setActiveTab] = useState(0);

	const showUpdateButton = useMemo(
		() =>
			data.start_date !== filters.start_date ||
			data.end_date !== filters.end_date ||
			(data.date_range || "") !== (filters.date_range || "") ||
			(data.completed || "") !== (filters.completed || "") ||
			(data.brand || "") !== (filters.brand || "") ||
			(data.phone_call_intent || "") !== (filters.phone_call_intent || "") ||
			(data.callback_info || "") !== (filters.callback_info || ""),
		[data, filters],
	);

	const update = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-appointments.metrics"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const handleDateRangeChange = (value) => {
		setData("date_range", value);

		const today = new Date();
		const format = (date) => date.toISOString().slice(0, 10);

		if (value === "today") {
			const d = format(today);
			setData("start_date", d);
			setData("end_date", d);
			return;
		}

		if (value === "last_7_days") {
			const start = new Date(today);
			start.setDate(start.getDate() - 7);
			setData("start_date", format(start));
			setData("end_date", format(today));
			return;
		}

		if (value === "last_6_months") {
			const start = new Date(today);
			start.setMonth(start.getMonth() - 6);
			setData("start_date", format(start));
			setData("end_date", format(today));
		}
	};

	const solicitudesData = useMemo(
		() =>
			(requestedVsConfirmed ?? []).map((r) => ({
				...r,
				pendientes_de_confirmar: r.diferencia_solicitudes_confirmadas,
			})),
		[requestedVsConfirmed],
	);
	const pieSummaryData = useMemo(() => {
		const total = Number(summary.total_solicitudes ?? 0);
		const confirmadas = Number(summary.total_confirmadas ?? 0);
		return [
			{ name: "Agendadas (fecha + sucursal)", value: confirmadas, color: "#22c55e" },
			{
				name: "Sin agendar",
				value: Math.max(total - confirmadas, 0),
				color: "#0ea5e9",
			},
		];
	}, [summary]);

	const pieRevenueData = useMemo(() => {
		const conf = Number(summary.catalogo_cents_confirmadas ?? 0);
		const pend = Number(summary.catalogo_cents_pendientes ?? 0);
		return [
			{ name: "Catálogo — agendadas", value: conf, color: "#22c55e" },
			{
				name: "Catálogo — pendiente de agendar",
				value: Math.max(pend, 0),
				color: "#0ea5e9",
			},
		];
	}, [summary]);

	const ingresosTotalPeriodoCents = useMemo(
		() => Number(summary.catalogo_cents_solicitadas ?? 0),
		[summary],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (data.date_range === "today") {
			badges.push(
				<Badge key="range-today" color="sky">
					<CalendarDaysIcon className="size-4" />
					Hoy
				</Badge>,
			);
		} else if (data.date_range === "last_7_days") {
			badges.push(
				<Badge key="range-7" color="sky">
					<CalendarDaysIcon className="size-4" />
					Últimos 7 días
				</Badge>,
			);
		} else if (data.date_range === "last_6_months") {
			badges.push(
				<Badge key="range-6m" color="sky">
					<CalendarDaysIcon className="size-4" />
					Últimos 6 meses
				</Badge>,
			);
		}

		if (data.completed === "true") {
			badges.push(
				<Badge key="completed-true" color="emerald">
					<CheckCircleIcon className="size-4" />
					Confirmadas
				</Badge>,
			);
		} else if (data.completed === "false") {
			badges.push(
				<Badge key="completed-false" color="slate">
					<ClockIcon className="size-4" />
					No confirmadas
				</Badge>,
			);
		}

		if (data.brand) {
			const label = brands?.find((b) => b.value === data.brand)?.label || data.brand;
			badges.push(
				<Badge key={`brand-${data.brand}`} color="famedic-lime">
					{label}
				</Badge>,
			);
		}

		if (data.phone_call_intent === "true") {
			badges.push(
				<Badge key="intent-yes" color="emerald">
					<PhoneIcon className="size-4" />
					Intentó llamar
				</Badge>,
			);
		} else if (data.phone_call_intent === "false") {
			badges.push(
				<Badge key="intent-no" color="slate">
					<PhoneIcon className="size-4" />
					No intentó llamar
				</Badge>,
			);
		}

		if (data.callback_info === "true") {
			badges.push(
				<Badge key="callback-yes" color="emerald">
					<ChatBubbleLeftRightIcon className="size-4" />
					Dejó info callback
				</Badge>,
			);
		} else if (data.callback_info === "false") {
			badges.push(
				<Badge key="callback-no" color="slate">
					<ChatBubbleLeftRightIcon className="size-4" />
					Sin info callback
				</Badge>,
			);
		}

		return badges;
	}, [data, brands]);

	return (
		<AdminLayout title="Métricas de citas de laboratorio">
			<div className="space-y-10">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Métricas de citas</Heading>
					<div className="flex items-center gap-2">
						<Button
							outline
							onClick={() => setShowFilters((v) => !v)}
						>
							<AdjustmentsHorizontalIcon className="size-4" />
							Filtros
							<FilterCountBadge count={filterBadges.length} />
						</Button>
						<Button
							type="button"
							onClick={update}
							disabled={processing || !showUpdateButton}
						>
							Actualizar
						</Button>
					</div>
				</div>

				<form onSubmit={update} className="space-y-4">
					<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								Fecha inicio
							</Text>
							<input
								type="date"
								value={data.start_date}
								onChange={(e) => setData("start_date", e.target.value)}
								className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:[color-scheme:dark]"
							/>
						</div>
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								Fecha fin
							</Text>
							<input
								type="date"
								value={data.end_date}
								onChange={(e) => setData("end_date", e.target.value)}
								className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:[color-scheme:dark]"
							/>
						</div>
					</div>

					{showFilters ? (
						<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
							<ListboxFilter
								label="Rango rápido"
								value={data.date_range}
								onChange={handleDateRangeChange}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Sin preset</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="today" className="group">
									<CalendarDaysIcon />
									<ListboxLabel>Citas de hoy</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="last_7_days" className="group">
									<CalendarDaysIcon />
									<ListboxLabel>Últimos 7 días</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="last_6_months" className="group">
									<CalendarDaysIcon />
									<ListboxLabel>Últimos 6 meses</ListboxLabel>
								</ListboxOption>
							</ListboxFilter>

							<ListboxFilter
								label="Estado"
								value={data.completed}
								onChange={(value) => setData("completed", value)}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="true" className="group">
									<CheckCircleIcon />
									<ListboxLabel>Confirmadas</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="false" className="group">
									<ClockIcon />
									<ListboxLabel>No confirmadas</ListboxLabel>
								</ListboxOption>
							</ListboxFilter>

							<ListboxFilter
								label="Marca de laboratorio"
								value={data.brand}
								onChange={(value) => setData("brand", value)}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Todas</ListboxLabel>
								</ListboxOption>
								{(brands ?? []).map((brand) => (
									<ListboxOption key={brand.value} value={brand.value} className="group">
										<ListboxLabel>{brand.label}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>

							<ListboxFilter
								label="Intento de llamada"
								value={data.phone_call_intent}
								onChange={(value) => setData("phone_call_intent", value)}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="true" className="group">
									<PhoneIcon />
									<ListboxLabel>Sí intentó llamar</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="false" className="group">
									<PhoneIcon />
									<ListboxLabel>No intentó llamar</ListboxLabel>
								</ListboxOption>
							</ListboxFilter>

							<ListboxFilter
								label="Info para callback"
								value={data.callback_info}
								onChange={(value) => setData("callback_info", value)}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="true" className="group">
									<ChatBubbleLeftRightIcon />
									<ListboxLabel>Dejó información</ListboxLabel>
								</ListboxOption>
								<ListboxOption value="false" className="group">
									<ChatBubbleLeftRightIcon />
									<ListboxLabel>No dejó información</ListboxLabel>
								</ListboxOption>
							</ListboxFilter>
						</div>
					) : null}

					{filterBadges.length > 0 ? (
						<div className="flex flex-wrap gap-2">{filterBadges}</div>
					) : null}
				</form>

				<TabGroup selectedIndex={activeTab} onChange={setActiveTab}>
					<TabList className="gap-2 rounded-lg border border-zinc-200 p-1 dark:border-zinc-700">
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Resumen
								</div>
							)}
						</Tab>
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Tendencias
								</div>
							)}
						</Tab>
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Por día
								</div>
							)}
						</Tab>
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Estudios y categorías
								</div>
							)}
						</Tab>
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Desgloses
								</div>
							)}
						</Tab>
						<Tab className="flex-1">
							{(selected) => (
								<div
									className={`w-full rounded-md px-3 py-2 text-sm font-medium ${
										selected
											? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
											: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
									}`}
								>
									Glosario
								</div>
							)}
						</Tab>
					</TabList>
				</TabGroup>

				{activeTab === 0 && (
				<section className="space-y-3">
					<Subheading>Resumen</Subheading>
					<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
						<CardKpi title="Solicitudes en el periodo">
							{summary.total_solicitudes}
						</CardKpi>
						<CardKpi
							title="Citas agendadas"
							hint="Tienen fecha y hora de cita y sucursal asignada."
						>
							{summary.total_confirmadas}
						</CardKpi>
						<CardKpi
							title="Compras concretadas"
							hint="Pedido con al menos un cobro registrado (transacción no fallida)."
						>
							{summary.total_compras_concretadas ?? "—"}
						</CardKpi>
						<CardKpi title="% agendadas / solicitudes">
							{summary.tasa_confirmacion_sobre_solicitudes != null
								? `${summary.tasa_confirmacion_sobre_solicitudes} %`
								: "—"}
						</CardKpi>
						<CardKpi title="% compras concretadas / agendadas">
							{summary.tasa_compra_sobre_confirmadas != null
								? `${summary.tasa_compra_sobre_confirmadas} %`
								: "—"}
						</CardKpi>
						<CardKpi
							title="$ catálogo (solicitadas)"
							hint={
								summary.pct_catalogo_confirmadas_vs_solicitadas != null
									? `Referencia 100 %. Las agendadas: ${summary.pct_catalogo_confirmadas_vs_solicitadas} % de este monto.`
									: "Precio Famedic en ítems del carrito (estudios con cita, misma marca)."
							}
						>
							{formatMxnFromCents(summary.catalogo_cents_solicitadas)}
						</CardKpi>
						<CardKpi
							title="$ catálogo (solo agendadas)"
							hint={
								summary.pct_catalogo_confirmadas_vs_solicitadas != null
									? `${summary.pct_catalogo_confirmadas_vs_solicitadas} % del catálogo de solicitadas.`
									: null
							}
						>
							{formatMxnFromCents(summary.catalogo_cents_confirmadas)}
						</CardKpi>
						<CardKpi
							title="$ compra concretada (con pago)"
							hint={
								summary.pct_compra_vs_catalogo_solicitadas != null
									? [
											`${summary.pct_compra_vs_catalogo_solicitadas} % del catálogo solicitadas`,
											summary.pct_compra_vs_catalogo_confirmadas != null
												? `${summary.pct_compra_vs_catalogo_confirmadas} % del catálogo agendadas`
												: null,
									  ]
											.filter(Boolean)
											.join("; ")
											.concat(".")
									: "Suma de precios en ítems del pedido, solo citas con pago."
							}
						>
							{formatMxnFromCents(summary.compra_cents_total)}
						</CardKpi>
						<CardKpi
							title="Promedio (solicitud → cita agendada)"
							hint="Desde la solicitud hasta fecha/hora de cita; solo citas ya agendadas."
						>
							{formatHours(
								summary.promedio_horas_solicitud_confirmacion_confirmadas,
							)}
						</CardKpi>
						<CardKpi
							title="Promedio (cita agendada → pago)"
							hint="Desde fecha/hora de cita hasta primer cobro; solo con pago registrado."
						>
							{formatAvgHoursOrMinutes(summary.promedio_horas_agenda_a_pago)}
						</CardKpi>
						<CardKpi
							title="Promedio (solicitud → pago)"
							hint="Solo compras concretadas; primer cobro registrado."
						>
							{formatHours(summary.promedio_horas_solicitud_a_pago)}
						</CardKpi>
						<CardKpi title="Pacientes con intento de llamada">
							{summary.pacientes_con_intento_llamada}
						</CardKpi>
						<CardKpi title="Disponibilidad o comentario">
							{summary.pacientes_con_disponibilidad_o_comentario}
						</CardKpi>
					</div>
					<div className="mt-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Solicitudes vs citas agendadas (distribución)
						</Text>
						<Text className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
							Por citas: agendada = tiene fecha/hora de cita y sucursal. Por $: el
							pastel parte el catálogo Famedic entre citas ya agendadas y el
							restante (carrito sin cita completa agendada). El total de{" "}
							<strong>compra concretada (con pago)</strong> va aparte, abajo.
						</Text>
						<div className="mt-4 grid gap-8 lg:grid-cols-2">
							<div className="space-y-3">
								<Text className="text-xs font-medium text-zinc-700 dark:text-zinc-300">
									Por citas
								</Text>
								<div className="grid items-center gap-4 sm:grid-cols-2">
									<ResponsiveContainer height={260}>
										<PieChart>
											<Pie
												data={pieSummaryData}
												cx="50%"
												cy="50%"
												innerRadius={58}
												outerRadius={92}
												paddingAngle={2}
												dataKey="value"
												nameKey="name"
											>
												{pieSummaryData.map((entry) => (
													<Cell key={entry.name} fill={entry.color} />
												))}
											</Pie>
											<Tooltip
												content={({ active, payload }) =>
													active && payload?.length ? (
														<RechartsTooltipCard
															title={payload[0]?.name}
															rows={[
																{
																	label: "Citas",
																	value: payload[0]?.value ?? "—",
																},
															]}
														/>
													) : null
												}
											/>
											<Legend />
										</PieChart>
									</ResponsiveContainer>
									<div className="space-y-2">
										{pieSummaryData.map((item) => (
											<div
												key={item.name}
												className="flex items-center justify-between rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700"
											>
												<div className="flex items-center gap-2">
													<span
														className="size-3 rounded-full"
														style={{ backgroundColor: item.color }}
													/>
													<Text>{item.name}</Text>
												</div>
												<Text className="font-semibold text-zinc-900 dark:text-zinc-100">
													{item.value}
												</Text>
											</div>
										))}
									</div>
								</div>
							</div>
							<div className="space-y-3">
								<Text className="text-xs font-medium text-zinc-700 dark:text-zinc-300">
									Por ingresos ($)
								</Text>
								{ingresosTotalPeriodoCents === 0 ? (
									<div className="flex min-h-[200px] items-center justify-center rounded-lg border border-dashed border-zinc-200 px-4 py-8 text-center dark:border-zinc-600">
										<Text className="text-sm text-zinc-500 dark:text-zinc-400">
											Sin montos de catálogo en el periodo: no hay líneas de
											carrito sin estudios con cita o sin precio en catálogo.
										</Text>
									</div>
								) : (
									<div className="grid items-center gap-4 sm:grid-cols-2">
										<ResponsiveContainer height={260}>
											<PieChart>
												<Pie
													data={pieRevenueData}
													cx="50%"
													cy="50%"
													innerRadius={58}
													outerRadius={92}
													paddingAngle={2}
													dataKey="value"
													nameKey="name"
												>
													{pieRevenueData.map((entry) => (
														<Cell key={entry.name} fill={entry.color} />
													))}
												</Pie>
												<Tooltip
													content={({ active, payload }) => {
														if (!active || !payload?.length) {
															return null;
														}
														const v = Number(payload[0]?.value ?? 0);
														const total = ingresosTotalPeriodoCents;
														const pct =
															total > 0
																? ((v / total) * 100).toFixed(1)
																: null;
														return (
															<RechartsTooltipCard
																title={payload[0]?.name}
																rows={[
																	{
																		label: "Monto",
																		value: formatMxnFromCents(v),
																	},
																	{
																		label: "% del catálogo total",
																		value:
																			pct != null ? `${pct} %` : "—",
																	},
																]}
															/>
														);
													}}
												/>
												<Legend />
											</PieChart>
										</ResponsiveContainer>
										<div className="space-y-2">
											{pieRevenueData.map((item) => {
												const total = ingresosTotalPeriodoCents;
												const pct =
													total > 0
														? ((Number(item.value) / total) * 100).toFixed(1)
														: null;
												return (
													<div
														key={item.name}
														className="flex items-center justify-between rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700"
													>
														<div className="flex items-center gap-2">
															<span
																className="size-3 rounded-full"
																style={{ backgroundColor: item.color }}
															/>
															<Text className="leading-snug">{item.name}</Text>
														</div>
														<div className="text-right">
															<Text className="font-semibold text-zinc-900 dark:text-zinc-100">
																{formatMxnFromCents(item.value)}
															</Text>
															{pct != null && (
																<Text className="text-xs text-zinc-500 dark:text-zinc-400">
																	{pct} % del total
																</Text>
															)}
														</div>
													</div>
												);
											})}
											<div className="space-y-1 rounded-md border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800/50">
												<Text className="text-xs text-zinc-600 dark:text-zinc-400">
													Total catálogo (solicitadas):{" "}
													<span className="font-semibold text-zinc-800 dark:text-zinc-200">
														{formatMxnFromCents(ingresosTotalPeriodoCents)}
													</span>
												</Text>
												<Text className="text-xs text-zinc-600 dark:text-zinc-400">
													Total compra concretada (con pago):{" "}
													<span className="font-semibold text-zinc-800 dark:text-zinc-200">
														{formatMxnFromCents(summary.compra_cents_total)}
													</span>
												</Text>
											</div>
										</div>
									</div>
								)}
							</div>
						</div>
					</div>
				</section>
				)}

				{activeTab === 1 && (
				<>
				<section className="space-y-4">
					<Subheading>Volumen e ingresos por mes</Subheading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Citas creadas en el periodo (eje X = mes de la solicitud).{" "}
						<strong>$ Catálogo</strong> = precio Famedic del estudio (
						<code className="rounded bg-zinc-100 px-1 dark:bg-zinc-800">
							laboratory_tests.famedic_price_cents
						</code>
						) desde el <strong>carrito del paciente</strong> (
						<code className="rounded bg-zinc-100 px-1 dark:bg-zinc-800">
							laboratory_cart_items
						</code>
						, estudios con cita y misma marca). Las series
						en verde/naranja usan <strong>agendadas</strong> (fecha/hora + sucursal)
						y <strong>compra solo con pago</strong> (compra concretada).
					</Text>
					<div className="grid gap-6 xl:grid-cols-2">
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
								Solicitudes, agendadas y montos ($)
							</Text>
							<ResponsiveContainer height={340} className="mt-3">
								<BarChart
									data={solicitudesData}
									margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-zinc-300`}
								>
									<CartesianGrid vertical={false} />
									<XAxis
										dataKey="label"
										tickLine={false}
										axisLine={false}
										className="text-xs"
									/>
									<YAxis
										yAxisId="left"
										tickLine={false}
										axisLine={false}
										className="text-xs"
										width={40}
									/>
									<YAxis
										yAxisId="right"
										orientation="right"
										tickLine={false}
										axisLine={false}
										className="text-xs"
										width={56}
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												style: "currency",
												currency: "MXN",
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(Number(v) / 100)
										}
									/>
									<Tooltip
										cursor={{
											strokeWidth: 1.5,
											strokeDasharray: "6 3",
										}}
										content={<SolicitudesTooltip />}
									/>
									<Legend />
									<Bar
										yAxisId="left"
										dataKey="solicitudes"
										name="Solicitudes"
										fill="#0ea5e9"
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										yAxisId="left"
										dataKey="confirmadas"
										name="Agendadas"
										fill="#22c55e"
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										yAxisId="right"
										dataKey="ingresos_catalogo_solicitadas_cents"
										name="$ Catálogo (solicitadas)"
										fill="#a855f7"
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										yAxisId="right"
										dataKey="ingresos_catalogo_confirmadas_cents"
										name="$ Catálogo (agendadas)"
										fill="#10b981"
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										yAxisId="right"
										dataKey="ingresos_compra_cents"
										name="$ Compra (con pago)"
										fill="#f59e0b"
										radius={[4, 4, 0, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
							<Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
								Tres series en pesos (eje derecho). El tooltip detalla catálogo
								vs compra.
							</Text>
						</div>

						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
								Ingresos y variación vs mes anterior
							</Text>
							<Text className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
								Barras: venta real del mes (suma de precios en ítems de compra).
								Línea: variación de esa venta frente al mes previo (primer mes
								sin variación).
							</Text>
							<ResponsiveContainer height={340} className="mt-3">
								<ComposedChart
									data={monthlyRevenueAndDelta ?? []}
									margin={{ top: 8, right: 12, left: 0, bottom: 0 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50`}
								>
									<CartesianGrid vertical={false} />
									<XAxis
										dataKey="label"
										tickLine={false}
										axisLine={false}
										className="text-xs"
									/>
									<YAxis
										yAxisId="left"
										tickLine={false}
										axisLine={false}
										className="text-xs"
										width={52}
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(v / 100)
										}
									/>
									<YAxis
										yAxisId="right"
										orientation="right"
										tickLine={false}
										axisLine={false}
										className="text-xs"
										width={52}
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(v / 100)
										}
									/>
									<Tooltip content={<IngresosVariacionTooltip />} />
									<Legend />
									<Bar
										yAxisId="left"
										dataKey="ingresos_cents"
										name="Venta real (compra)"
										fill="#6366f1"
										radius={[4, 4, 0, 0]}
									/>
									<Line
										yAxisId="right"
										type="monotone"
										dataKey="variacion_mes_anterior_cents"
										name="Δ vs mes ant."
										stroke="#f97316"
										strokeWidth={2}
										dot={{ r: 3 }}
										connectNulls={false}
									/>
								</ComposedChart>
							</ResponsiveContainer>
						</div>
					</div>
				</section>

				<section className="space-y-4">
					<Subheading>Por laboratorio (marca)</Subheading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Conteos por marca; en pesos: catálogo Famedic desde carrito,
						catálogo solo de citas agendadas, y monto de ítems solo en compras con
						pago registrado.
					</Text>
					<div className="grid gap-6 lg:grid-cols-2">
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
								Solicitudes y agendadas
							</Text>
							<ResponsiveContainer height={280} className="mt-3">
								<BarChart
									layout="vertical"
									data={byBrand}
									margin={{ left: 8, right: 16 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-zinc-300`}
								>
									<CartesianGrid horizontal={false} />
									<XAxis type="number" hide />
									<YAxis
										type="category"
										dataKey="label"
										width={88}
										tickLine={false}
										axisLine={false}
										className="text-xs"
									/>
									<Tooltip content={<BrandTooltip />} />
									<Legend />
									<Bar
										dataKey="total"
										name="Solicitudes"
										fill="#6366f1"
										radius={[0, 4, 4, 0]}
									>
										<LabelList
											dataKey="total"
											position="right"
											className="fill-zinc-700 text-[10px] dark:fill-zinc-200"
										/>
									</Bar>
									<Bar
										dataKey="confirmadas"
										name="Agendadas"
										fill="#10b981"
										radius={[0, 4, 4, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
								Montos ($) por laboratorio
							</Text>
							<ResponsiveContainer height={280} className="mt-3">
								<BarChart
									layout="vertical"
									data={byBrand}
									margin={{ left: 8, right: 28 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50`}
								>
									<CartesianGrid horizontal={false} />
									<XAxis
										type="number"
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												style: "currency",
												currency: "MXN",
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(Number(v) / 100)
										}
										className="text-xs"
									/>
									<YAxis
										type="category"
										dataKey="label"
										width={88}
										tickLine={false}
										axisLine={false}
										className="text-xs"
									/>
									<Tooltip content={<BrandTooltip />} />
									<Legend />
									<Bar
										dataKey="catalogo_solicitadas_cents"
										name="$ Catálogo (solicitadas)"
										fill="#a855f7"
										radius={[0, 4, 4, 0]}
									>
										<LabelList
											dataKey="catalogo_solicitadas_cents"
											position="right"
											className="fill-zinc-700 text-[10px] dark:fill-zinc-200"
											formatter={(c) => formatMxnFromCents(c)}
										/>
									</Bar>
									<Bar
										dataKey="catalogo_confirmadas_cents"
										name="$ Catálogo (agendadas)"
										fill="#10b981"
										radius={[0, 4, 4, 0]}
									/>
									<Bar
										dataKey="compra_cents"
										name="$ Compra (con pago)"
										fill="#f59e0b"
										radius={[0, 4, 4, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					</div>
				</section>
				</>
				)}

				{activeTab === 2 && (
				<section className="space-y-4">
					<Subheading>Actividad diaria</Subheading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Conteos por día de creación de la solicitud. &quot;Compras
						concretadas&quot; = pedido con pago registrado (transacción no fallida).
						Los montos en $ están en el tooltip.
					</Text>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<ResponsiveContainer height={380} className="mt-2">
							<BarChart
								data={dailySeries ?? []}
								margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
								className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-zinc-300`}
							>
								<CartesianGrid vertical={false} />
								<XAxis
									dataKey="label"
									tickLine={false}
									axisLine={false}
									className="text-xs"
								/>
								<YAxis
									tickLine={false}
									axisLine={false}
									className="text-xs"
									width={40}
								/>
								<Tooltip content={<DailyActivityTooltip />} />
								<Legend />
								<Bar dataKey="solicitudes" name="Solicitadas" fill="#0ea5e9" radius={[4, 4, 0, 0]} />
								<Bar dataKey="confirmadas" name="Agendadas" fill="#22c55e" radius={[4, 4, 0, 0]} />
								<Bar dataKey="logradas" name="Compras concretadas" fill="#8b5cf6" radius={[4, 4, 0, 0]} />
								<Bar dataKey="intentos_llamada" name="Intentos de llamada" fill="#f59e0b" radius={[4, 4, 0, 0]} />
							</BarChart>
						</ResponsiveContainer>
					</div>
				</section>
				)}

				{activeTab === 3 && (
				<section className="grid gap-6 xl:grid-cols-2">
					<div className="space-y-2">
						<Subheading>Montos por tipo de estudio</Subheading>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Por nombre de estudio en catálogo (match{" "}
							<Strong>gda_id</Strong> + marca). Tres series: catálogo en
							solicitudes y en citas agendadas, y precio en ítems solo si hubo
							pago registrado.
						</Text>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<ResponsiveContainer
								height={Math.max(320, (byStudyName?.length ?? 0) * 36)}
								className="mt-2 min-h-[320px]"
							>
								<BarChart
									layout="vertical"
									data={byStudyName ?? []}
									margin={{ left: 4, right: 12 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50`}
								>
									<CartesianGrid horizontal={false} />
									<XAxis
										type="number"
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(v / 100)
										}
										className="text-xs"
									/>
									<YAxis
										type="category"
										dataKey="label"
										width={200}
										tickLine={false}
										axisLine={false}
										className="text-[11px] text-zinc-600 dark:text-zinc-400"
									/>
									<Tooltip content={<VentaBarTooltip />} />
									<Legend />
									<Bar
										dataKey="catalogo_solicitadas_cents"
										name="$ Catálogo (solicitadas)"
										fill="#a855f7"
										radius={[0, 4, 4, 0]}
									/>
									<Bar
										dataKey="catalogo_confirmadas_cents"
										name="$ Catálogo (agendadas)"
										fill="#10b981"
										radius={[0, 4, 4, 0]}
									/>
									<Bar
										dataKey="compra_cents"
										name="$ Compra"
										fill="#f59e0b"
										radius={[0, 4, 4, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					</div>

					<div className="space-y-2">
						<Subheading>Montos por categoría de estudio</Subheading>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Agrupado por categoría del estudio en catálogo; sin match puede
							figurar como “Sin categoría en catálogo”.
						</Text>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<ResponsiveContainer
								height={Math.max(320, (byCategory?.length ?? 0) * 40)}
								className="mt-2 min-h-[320px]"
							>
								<BarChart
									layout="vertical"
									data={byCategory ?? []}
									margin={{ left: 4, right: 12 }}
									className={`${chartUi} [&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-600/50`}
								>
									<CartesianGrid horizontal={false} />
									<XAxis
										type="number"
										tickFormatter={(v) =>
											new Intl.NumberFormat("es-MX", {
												notation: "compact",
												maximumFractionDigits: 1,
											}).format(v / 100)
										}
										className="text-xs"
									/>
									<YAxis
										type="category"
										dataKey="label"
										width={180}
										tickLine={false}
										axisLine={false}
										className="text-[11px] text-zinc-600 dark:text-zinc-400"
									/>
									<Tooltip content={<VentaBarTooltip />} />
									<Legend />
									<Bar
										dataKey="catalogo_solicitadas_cents"
										name="$ Catálogo (solicitadas)"
										fill="#a855f7"
										radius={[0, 4, 4, 0]}
									/>
									<Bar
										dataKey="catalogo_confirmadas_cents"
										name="$ Catálogo (agendadas)"
										fill="#10b981"
										radius={[0, 4, 4, 0]}
									/>
									<Bar
										dataKey="compra_cents"
										name="$ Compra"
										fill="#f59e0b"
										radius={[0, 4, 4, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					</div>
				</section>
				)}

				{activeTab === 4 && (
				<section className="space-y-8">
					<Subheading>Desgloses (tablas de depuración)</Subheading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Mismos datos que alimentan las gráficas, en formato fila/columna para
						validar totales. La columna <Strong>diferencia catálogo</Strong> es
						solicitadas − agendadas en $ Famedic (citas sin fecha/hora y sucursal
						completas). <Strong>$ Compra</Strong> solo suma citas con pago
						registrado.
					</Text>

					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Filtros aplicados
						</Text>
						<div className="overflow-x-auto">
							<table className={tableShell}>
								<thead>
									<tr>
										<th className={`${tableHead} px-3 py-2`}>Filtro</th>
										<th className={`${tableHead} px-3 py-2`}>Valor enviado</th>
										<th className={`${tableHead} px-3 py-2`}>Interpretación</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td className={tableCell}>Fecha inicio</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.start_date ?? "—"}
										</td>
										<td className={tableCell}>
											Inicio del rango sobre{" "}
											<code className="text-xs">created_at</code>
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Fecha fin</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.end_date ?? "—"}
										</td>
										<td className={tableCell}>Fin del rango (fin de día)</td>
									</tr>
									<tr>
										<td className={tableCell}>Rango rápido</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.date_range || "(vacío)"}
										</td>
										<td className={tableCell}>
											Preset de fechas si se usó (puede coexistir con fechas
											manuales)
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Estado (confirmación)</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.completed || "(todos)"}
										</td>
										<td className={tableCell}>
											<code>true</code> = solo confirmadas,{" "}
											<code>false</code> = sin confirmar, vacío = todas
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Marca</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.brand || "(todas)"}
										</td>
										<td className={tableCell}>
											{(brands ?? []).find((b) => b.value === filters.brand)
												?.label ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Intento de llamada</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.phone_call_intent || "(todos)"}
										</td>
										<td className={tableCell}>
											<code>true</code> / <code>false</code> / vacío
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Info callback</td>
										<td className={`${tableCell} font-mono text-xs`}>
											{filters.callback_info || "(todos)"}
										</td>
										<td className={tableCell}>
											<code>true</code> / <code>false</code> / vacío
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Totales del periodo (conteos y montos)
						</Text>
						<div className="overflow-x-auto">
							<table className={tableShell}>
								<thead>
									<tr>
										<th className={`${tableHead} px-3 py-2`}>Concepto</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											Valor
										</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td className={tableCell}>Citas solicitadas (en rango)</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_solicitadas ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>
											Citas agendadas (fecha/hora + sucursal)
										</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_confirmadas ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Citas pendientes de agendar</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_pendientes_confirmar ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Citas con compra registrada</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_con_compra ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>
											Citas con monto catálogo &gt; 0 (carrito)
										</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_con_monto_catalogo ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>Citas con pago registrado</td>
										<td className={tableCellNum}>
											{desgloses?.totales?.citas_con_pago_registrado ?? "—"}
										</td>
									</tr>
									<tr>
										<td className={`${tableCell} font-medium`}>
											$ Catálogo — todas las solicitadas
										</td>
										<td className={`${tableCellNum} font-medium`}>
											{formatMxnFromCents(
												desgloses?.totales?.catalogo_cents_solicitadas,
											)}
										</td>
									</tr>
									<tr>
										<td className={`${tableCell} font-medium`}>
											$ Catálogo — solo citas agendadas
										</td>
										<td className={`${tableCellNum} font-medium`}>
											{formatMxnFromCents(
												desgloses?.totales?.catalogo_cents_confirmadas,
											)}
										</td>
									</tr>
									<tr>
										<td className={`${tableCell} font-medium text-amber-800 dark:text-amber-200`}>
											$ Diferencia catálogo (solicitadas − confirmadas)
										</td>
										<td className={`${tableCellNum} font-medium text-amber-800 dark:text-amber-200`}>
											{formatMxnFromCents(
												desgloses?.totales
													?.catalogo_cents_diferencia_solicitadas_menos_confirmadas,
											)}
										</td>
									</tr>
									<tr>
										<td className={`${tableCell} font-medium`}>
											$ Compra concretada (pedido con pago)
										</td>
										<td className={`${tableCellNum} font-medium`}>
											{formatMxnFromCents(desgloses?.totales?.compra_cents_total)}
										</td>
									</tr>
									<tr>
										<td className={tableCell}>
											$ En pedidos sin pago aún (referencia)
										</td>
										<td className={tableCellNum}>
											{formatMxnFromCents(
												desgloses?.totales?.compra_cents_solo_pedidos_sin_pago,
											)}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Desglose por mes (misma serie que Tendencias)
						</Text>
						<div className="overflow-x-auto">
							<table className={tableShell}>
								<thead>
									<tr>
										<th className={`${tableHead} px-3 py-2`}>Mes</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>Solic.</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>Agend.</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Cat. solicitadas
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Cat. agendadas
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Δ cat. (sol−agend.)
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Compra (pago)
										</th>
									</tr>
								</thead>
								<tbody>
									{(requestedVsConfirmed ?? []).map((row) => {
										const catSol = Number(
											row.ingresos_catalogo_solicitadas_cents ??
												row.ingresos_cents_solicitudes ??
												0,
										);
										const catConf = Number(
											row.ingresos_catalogo_confirmadas_cents ??
												row.ingresos_cents_confirmadas ??
												0,
										);
										const diff = catSol - catConf;
										const compra = Number(row.ingresos_compra_cents ?? 0);
										return (
											<tr key={row.period ?? row.label}>
												<td className={tableCell}>{row.label}</td>
												<td className={tableCellNum}>{row.solicitudes}</td>
												<td className={tableCellNum}>{row.confirmadas}</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(catSol)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(catConf)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(diff)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(compra)}
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						</div>
					</div>

					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Desglose por laboratorio (marca)
						</Text>
						<div className="overflow-x-auto">
							<table className={tableShell}>
								<thead>
									<tr>
										<th className={`${tableHead} px-3 py-2`}>Marca</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>Solic.</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>Agend.</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Cat. solicitadas
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Cat. agendadas
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Δ cat. (sol−agend.)
										</th>
										<th className={`${tableHead} px-3 py-2 text-right`}>
											$ Compra (pago)
										</th>
									</tr>
								</thead>
								<tbody>
									{(byBrand ?? []).map((row) => {
										const catSol = Number(row.catalogo_solicitadas_cents ?? 0);
										const catConf = Number(
											row.catalogo_confirmadas_cents ?? 0,
										);
										const diff = catSol - catConf;
										return (
											<tr key={row.brand}>
												<td className={tableCell}>{row.label}</td>
												<td className={tableCellNum}>{row.total}</td>
												<td className={tableCellNum}>{row.confirmadas}</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(catSol)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(catConf)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(diff)}
												</td>
												<td className={tableCellNum}>
													{formatMxnFromCents(row.compra_cents)}
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						</div>
					</div>
				</section>
				)}

				{activeTab === 5 && (
				<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Glosario y transparencia de cálculos</Subheading>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
						Definiciones y fórmulas usadas en esta pantalla para que puedas
						interpretar cada métrica con claridad.
					</Text>
					<div className="mt-4 grid gap-4 md:grid-cols-2">
						<div className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
							<Text className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								Conceptos base
							</Text>
							<ul className="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
								<li>
									<Strong>Solicitudes:</Strong> citas en{" "}
									<code>laboratory_appointments</code> filtradas por{" "}
									<code>created_at</code> en el rango seleccionado.
								</li>
								<li>
									<Strong>Cita agendada (métricas):</Strong>{" "}
									<code>appointment_date</code> y <code>laboratory_store_id</code>{" "}
									no nulos (fecha/hora y sucursal).
								</li>
								<li>
									<Strong>Filtro &quot;Confirmadas&quot; (panel de citas):</Strong>{" "}
									sigue usando <code>confirmed_at</code> en la base; puede no
									coincidir con &quot;agendada&quot; de estas métricas.
								</li>
								<li>
									<Strong>Programadas (solo fecha):</Strong> solicitudes con{" "}
									<code>appointment_date</code> no nulo.
								</li>
								<li>
									<Strong>Intentó llamar:</Strong> registro con{" "}
									<code>phone_call_intent_at</code> no nulo.
								</li>
								<li>
									<Strong>Dejó info callback:</Strong> existe{" "}
									<code>callback_availability_starts_at</code>,{" "}
									<code>callback_availability_ends_at</code> o comentario.
								</li>
							</ul>
						</div>

						<div className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
							<Text className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								Fórmulas KPI
							</Text>
							<ul className="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
								<li>
									<Strong>% agendadas / programadas (fecha):</Strong>{" "}
									<code>(agendadas / citas con appointment_date) * 100</code>
								</li>
								<li>
									<Strong>% agendadas / solicitudes:</Strong>{" "}
									<code>(agendadas / solicitudes) * 100</code>
								</li>
								<li>
									<Strong>% compras concretadas / agendadas:</Strong>{" "}
									<code>(citas con pago / agendadas) * 100</code>
								</li>
								<li>
									<Strong>Promedio solicitud → cita agendada:</Strong> promedio
									de <code>diff(created_at, appointment_date)</code> en horas,
									solo citas agendadas.
								</li>
								<li>
									<Strong>Promedio cita agendada → pago:</Strong> desde{" "}
									<code>appointment_date</code> hasta el primer cobro registrado;
									solo citas con pago. Si el promedio es menor a 1 h, se muestra
									en minutos.
								</li>
								<li>
									<Strong>Promedio solicitud → pago:</Strong> promedio en horas
									desde <code>created_at</code> hasta el primer cobro; solo citas
									con compra concretada.
								</li>
							</ul>
						</div>
					</div>

					<div className="mt-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
						<Text className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
							Ingresos y agrupaciones
						</Text>
						<ul className="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
							<li>
								<Strong>$ Catálogo (por línea):</Strong> suma de{" "}
								<code>laboratory_tests.famedic_price_cents</code> en ítems del
								<strong> carrito</strong> del paciente (
								<code>laboratory_cart_items</code> unido a la cita por{" "}
								<code>customer_id</code>), solo estudios con{" "}
								<code>requires_appointment</code> y misma <code>brand</code> que
								la solicitud.
							</li>
							<li>
								<Strong>$ Compra (concretada):</Strong> suma de{" "}
								<code>laboratory_purchase_items.price_cents</code> solo en citas
								con al menos un cobro registrado (transacción no fallida con
								fecha).
							</li>
							<li>
								<Strong>Agregación mensual / diaria:</Strong> suma de los montos
								anteriores según el mes o día de <code>created_at</code> de la
								solicitud.
							</li>
							<li>
								<Strong>$ Catálogo solicitadas vs agendadas:</Strong> la misma base
								por línea; &quot;agendadas&quot; solo suma líneas cuya cita tiene
								fecha/hora y sucursal.
							</li>
							<li>
								<Strong>Variación mensual ($):</Strong> sobre la{" "}
								<strong>venta real</strong> (compra),{" "}
								<code>compra_mes_actual - compra_mes_anterior</code>.
							</li>
							<li>
								<Strong>Variación mensual (%):</Strong>{" "}
								<code>((mes_actual - mes_anterior) / mes_anterior) * 100</code>{" "}
								(si el mes anterior es mayor a 0).
							</li>
						</ul>
					</div>
				</section>
				)}
			</div>
		</AdminLayout>
	);
}
