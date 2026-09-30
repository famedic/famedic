import { useEffect, useMemo, useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import {
	MagnifyingGlassIcon,
	ClockIcon,
	CheckCircleIcon,
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
	CalendarDaysIcon,
	ArrowPathIcon,
	PhoneIcon,
	ChatBubbleLeftRightIcon,
	FunnelIcon,
	EyeIcon,
} from "@heroicons/react/16/solid";
import { BuildingStorefrontIcon } from "@heroicons/react/24/solid";
import { PresentationChartLineIcon } from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Checkbox } from "@/Components/Catalyst/checkbox";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Avatar } from "@/Components/Catalyst/avatar";
import {
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import SearchInput from "@/Components/Admin/SearchInput";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import StatusBadge from "@/Components/StatusBadge";
import DateFilter from "@/Components/Filters/DateFilter";
import LaboratoryAppointmentsDashboard from "@/Components/Admin/LaboratoryAppointmentsDashboard";

function dateRangePresetLabel(preset) {
	switch (preset) {
		case "today":
			return "Citas de hoy";
		case "last_7_days":
			return "Últimos 7 días";
		case "last_15_days":
			return "Últimos 15 días";
		case "last_30_days":
			return "Últimos 30 días";
		case "last_60_days":
			return "Últimos 60 días";
		case "last_6_months":
			return "Últimos 6 meses";
		default:
			return null;
	}
}

const LIST_FILTER_KEYS = [
	"search",
	"completed",
	"date_range",
	"brand",
	"phone_call_intent",
	"callback_info",
];

const PENDING_FILTER_KEYS = [
	"search",
	"brand",
	"pending_sort",
	"priority_filter",
];

export default function LaboratoryAppointments({
	laboratoryAppointments,
	filters,
	dashboard,
	appointmentSummary,
	brands,
	pendingCount = 0,
	canDeleteOld = false,
}) {
	const { auth } = usePage().props;
	const view = filters.view || "list";
	const firstName =
		auth?.user?.name?.split(" ")?.[0] ||
		auth?.user?.full_name?.split(" ")?.[0] ||
		"Concierge";

	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		completed: filters.completed || "",
		date_range: filters.date_range || "last_7_days",
		brand: filters.brand || "",
		phone_call_intent: filters.phone_call_intent || "",
		callback_info: filters.callback_info || "",
		view,
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		pending_sort: filters.pending_sort || "priority",
		priority_filter: filters.priority_filter || "",
	});

	useEffect(() => {
		setData({
			search: filters.search || "",
			completed: filters.completed || "",
			date_range: filters.date_range || "last_7_days",
			brand: filters.brand || "",
			phone_call_intent: filters.phone_call_intent || "",
			callback_info: filters.callback_info || "",
			view: filters.view || "list",
			start_date: filters.start_date || "",
			end_date: filters.end_date || "",
			pending_sort: filters.pending_sort || "priority",
			priority_filter: filters.priority_filter || "",
		});
	}, [
		filters.search,
		filters.completed,
		filters.date_range,
		filters.brand,
		filters.phone_call_intent,
		filters.callback_info,
		filters.view,
		filters.start_date,
		filters.end_date,
		filters.pending_sort,
		filters.priority_filter,
	]);

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			const query = Object.fromEntries(
				Object.entries(data).filter(
					([key, value]) =>
						value !== "" &&
						value !== null &&
						value !== undefined &&
						key !== "page",
				),
			);

			get(route("admin.laboratory-appointments.index", query), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(() => {
		if (view === "dashboard") {
			return (
				(data.start_date || "") !== (filters.start_date || "") ||
				(data.end_date || "") !== (filters.end_date || "")
			);
		}

		if (view === "pending") {
			return PENDING_FILTER_KEYS.some(
				(key) => (data[key] || "") !== (filters[key] || ""),
			);
		}

		return LIST_FILTER_KEYS.some(
			(key) => (data[key] || "") !== (filters[key] || ""),
		);
	}, [data, filters, view]);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky" key={`search-${filters.search}`}>
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (view === "list" && filters.completed === "false") {
			badges.push(
				<Badge color="slate" key="completed-false">
					<ClockIcon className="size-4" />
					solicitadas
				</Badge>,
			);
		} else if (view === "list" && filters.completed === "true") {
			badges.push(
				<StatusBadge isActive={true} activeText="confirmadas" />,
			);
		}

		if (view === "dashboard" && filters.start_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					desde {filters.start_date}
				</Badge>,
			);
		}

		if (view === "dashboard" && filters.end_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					hasta {filters.end_date}
				</Badge>,
			);
		}

		const dateRangeLabel = dateRangePresetLabel(filters.date_range);
		if (view === "list" && dateRangeLabel) {
			badges.push(
				<Badge color="sky" key={`range-${filters.date_range}`}>
					<CalendarDaysIcon className="size-4" />
					{dateRangeLabel}
				</Badge>,
			);
		}

		if (filters.brand) {
			const brandLabel =
				brands?.find((brand) => brand.value === filters.brand)?.label ||
				filters.brand;

			badges.push(
				<Badge color="famedic-lime" key={`brand-${filters.brand}`}>
					{brandLabel}
				</Badge>,
			);
		}

		if (view === "pending" && filters.pending_sort === "oldest") {
			badges.push(
				<Badge color="slate" key="pending-sort-oldest">
					<ClockIcon className="size-4" />
					Más antiguas primero
				</Badge>,
			);
		} else if (view === "pending" && filters.pending_sort === "newest") {
			badges.push(
				<Badge color="slate" key="pending-sort-newest">
					<ClockIcon className="size-4" />
					Más recientes primero
				</Badge>,
			);
		}

		if (view === "pending" && filters.priority_filter === "recent") {
			badges.push(
				<Badge color="emerald" key="priority-filter-recent">
					Actividad reciente
				</Badge>,
			);
		} else if (view === "pending" && filters.priority_filter === "active_cart") {
			badges.push(
				<Badge color="sky" key="priority-filter-active-cart">
					Con carrito activo
				</Badge>,
			);
		} else if (
			view === "pending" &&
			filters.priority_filter === "without_recent_activity"
		) {
			badges.push(
				<Badge color="zinc" key="priority-filter-without-activity">
					Sin actividad reciente
				</Badge>,
			);
		}

		if (view === "list" && filters.phone_call_intent === "true") {
			badges.push(
				<Badge color="emerald" key="phone-intent-true">
					<PhoneIcon className="size-4" />
					Intentó llamar
				</Badge>,
			);
		} else if (view === "list" && filters.phone_call_intent === "false") {
			badges.push(
				<Badge color="slate" key="phone-intent-false">
					<PhoneIcon className="size-4" />
					No intentó llamar
				</Badge>,
			);
		}

		if (view === "list" && filters.callback_info === "true") {
			badges.push(
				<Badge color="emerald" key="callback-info-true">
					<ChatBubbleLeftRightIcon className="size-4" />
					Dejó info de llamada
				</Badge>,
			);
		} else if (view === "list" && filters.callback_info === "false") {
			badges.push(
				<Badge color="slate" key="callback-info-false">
					<ChatBubbleLeftRightIcon className="size-4" />
					Sin info de llamada
				</Badge>,
			);
		}

		return badges;
	}, [filters, view, brands]);

	const filtersCount = useMemo(() => {
		if (view === "dashboard") {
			return ["start_date", "end_date"].filter((key) => filters[key]).length;
		}

		if (view === "pending") {
			return PENDING_FILTER_KEYS.filter((key) => {
				if (!filters[key]) {
					return false;
				}

				if (key === "pending_sort" && filters.pending_sort === "priority") {
					return false;
				}

				return true;
			}).length;
		}

		return LIST_FILTER_KEYS.filter((key) => filters[key]).length;
	}, [filters, view]);

	const listTabHref = route("admin.laboratory-appointments.index", {
		search: data.search || undefined,
		completed: "false",
		date_range: data.date_range || undefined,
		brand: data.brand || undefined,
		phone_call_intent: data.phone_call_intent || undefined,
		callback_info: data.callback_info || undefined,
		view: "list",
	});

	const confirmedTabHref = route("admin.laboratory-appointments.index", {
		search: data.search || undefined,
		completed: "true",
		date_range: data.date_range || undefined,
		brand: data.brand || undefined,
		phone_call_intent: data.phone_call_intent || undefined,
		callback_info: data.callback_info || undefined,
		view: "list",
	});

	const pendingTabHref = route("admin.laboratory-appointments.index", {
		search: data.search || undefined,
		brand: data.brand || undefined,
		pending_sort: data.pending_sort || "priority",
		priority_filter: data.priority_filter || undefined,
		view: "pending",
	});

	const dashboardTabHref = route("admin.laboratory-appointments.index", {
		view: "dashboard",
		start_date: data.start_date || undefined,
		end_date: data.end_date || undefined,
	});

	return (
		<AdminLayout title="Citas de laboratorio">
			<div className="space-y-6">
				<div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
						<div className="space-y-2">
							<div className="flex flex-wrap items-center gap-2">
								<Badge color="famedic-lime">Concierge</Badge>
								<Badge color="sky">Laboratorio</Badge>
							</div>
							<Heading>
								Hola{" "}
								<span className="text-famedic-dark dark:text-famedic-lime">
									{firstName}
								</span>
								, tienes {appointmentSummary?.pending ?? 0} citas pendientes de confirmar
							</Heading>
						</div>

						<div className="flex max-w-full overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-950">
							<Button
								href={listTabHref}
								outline={view !== "list" || filters.completed === "true"}
								className="shrink-0 rounded-lg !border-0"
							>
								Solicitadas
							</Button>
							<Button
								href={confirmedTabHref}
								outline={view !== "list" || filters.completed !== "true"}
								className="shrink-0 rounded-lg !border-0"
							>
								Confirmadas
								{appointmentSummary?.confirmed > 0 && (
									<Badge color="famedic-lime" className="ml-1.5">
										{appointmentSummary.confirmed}
									</Badge>
								)}
							</Button>
							<Button
								href={pendingTabHref}
								outline={view !== "pending"}
								className="shrink-0 rounded-lg !border-0"
							>
								Pendientes
								{pendingCount > 0 && (
									<Badge color="rose" className="ml-1.5">
										{pendingCount}
									</Badge>
								)}
							</Button>
							<Button
								href={dashboardTabHref}
								outline={view !== "dashboard"}
								className="shrink-0 rounded-lg !border-0"
							>
								<PresentationChartLineIcon className="size-5" />
								Dashboard
							</Button>
						</div>
					</div>

					{(view === "list" || view === "pending") && (
						<AppointmentKpiStrip
							laboratoryAppointments={laboratoryAppointments}
							appointmentSummary={appointmentSummary}
							pendingCount={pendingCount}
							view={view}
						/>
					)}
				</div>

				<form
					className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
					onSubmit={updateResults}
				>
					<div className="flex flex-col gap-4 lg:flex-row lg:items-end">
						<div className="min-w-0 flex-1">
							<Text className="mb-2 text-xs font-medium uppercase text-zinc-500">
								Buscar
							</Text>
							<SearchInput
								value={data.search}
								onChange={(value) => setData("search", value)}
								placeholder="Paciente, teléfono, correo, orden o folio..."
							/>
						</div>
						{(view === "list" || view === "pending") && (
							<Button
								outline
								type="button"
								className="lg:w-auto"
								onClick={() => setShowFilters(!showFilters)}
							>
								<FunnelIcon />
								Más filtros
								<FilterCountBadge count={filtersCount} />
							</Button>
						)}
						{showUpdateButton && (
							<Button type="submit" disabled={processing}>
								Filtrar
								<ArrowPathIcon className={processing ? "animate-spin" : ""} />
							</Button>
						)}
					</div>

					{showFilters && view === "list" && (
						<div className="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
							<Filters data={data} setData={setData} brands={brands} />
						</div>
					)}

					{(showFilters || view === "pending") && view === "pending" && (
						<div className="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800">
							<PendingFilters data={data} setData={setData} brands={brands} />
						</div>
					)}

					{view === "dashboard" && (
						<div className="mt-5 space-y-4 border-t border-zinc-100 pt-5 dark:border-zinc-800">
							<Text className="text-sm text-zinc-600 dark:text-zinc-400">
								Zona horaria Monterrey. Por defecto, últimos 30 días. Los filtros
								de estado (solicitadas / confirmadas) solo aplican a la pestaña
								Lista; aquí se consideran todas las citas en el rango.
							</Text>
							<div className="flex flex-wrap gap-4 items-end">
								<DateFilter
									label="Desde"
									value={data.start_date}
									onChange={(value) => setData("start_date", value)}
								/>
								<DateFilter
									label="Hasta"
									value={data.end_date}
									onChange={(value) => setData("end_date", value)}
								/>
							</div>
						</div>
					)}
				</form>

				{view === "dashboard" && filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">
						{filterBadges.map((badge, index) => (
							<span key={index}>{badge}</span>
						))}
					</div>
				)}

				{view === "dashboard" && dashboard && (
					<LaboratoryAppointmentsDashboard dashboard={dashboard} />
				)}

				{view === "list" && (
					<LaboratoryAppointmentsList
						laboratoryAppointments={laboratoryAppointments}
						filters={filters}
						filterBadges={filterBadges}
					/>
				)}

				{view === "pending" && (
					<LaboratoryAppointmentsPendingList
						laboratoryAppointments={laboratoryAppointments}
						filters={filters}
						filterBadges={filterBadges}
						canDeleteOld={canDeleteOld}
					/>
				)}
			</div>
		</AdminLayout>
	);
}

function AppointmentKpiStrip({
	laboratoryAppointments,
	appointmentSummary,
	pendingCount,
	view,
}) {
	const rows = laboratoryAppointments?.data || [];
	const activeCartCount = rows.filter((row) =>
		(row.admin_cart_status_label || "").toLowerCase().includes("activo"),
	).length;
	const recentActivityCount = rows.filter(
		(row) => row.concierge_cart_activity_signal?.color === "emerald",
	).length;
	const cleanupCount = rows.filter(
		(row) => row.is_old_delete_eligible === true,
	).length;

	if (view === "list") {
		return (
			<div className="mt-5 grid gap-3 md:grid-cols-3">
				<AppointmentMetricCard
					icon={ClockIcon}
					label="Citas pendientes del periodo"
					value={appointmentSummary?.pending ?? 0}
					description={appointmentSummary?.period_label || "Periodo filtrado"}
					tone="orange"
				/>
				<AppointmentMetricCard
					icon={CheckCircleIcon}
					label="Citas confirmadas"
					value={appointmentSummary?.confirmed ?? 0}
					description={appointmentSummary?.period_label || "Periodo filtrado"}
					tone="green"
				/>
				<AppointmentMetricCard
					icon={ArchiveBoxIcon}
					label="Citas pagadas"
					value={appointmentSummary?.paid ?? 0}
					description={appointmentSummary?.period_label || "Periodo filtrado"}
					tone="sky"
				/>
			</div>
		);
	}

	return (
		<div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
			<AppointmentMetricCard
				icon={ClockIcon}
				label="Pendientes de confirmar"
				value={pendingCount}
				description="Requieren agenda o seguimiento"
				tone="orange"
			/>
			<AppointmentMetricCard
				icon={CalendarDaysIcon}
				label="En esta página"
				value={rows.length}
				description="Resultados visibles"
				tone="blue"
			/>
			<AppointmentMetricCard
				icon={ArrowPathIcon}
				label="Actividad reciente"
				value={recentActivityCount}
				description="Últimas señales del paciente"
				tone="green"
			/>
			<AppointmentMetricCard
				icon={BuildingStorefrontIcon}
				label="Con carrito activo"
				value={activeCartCount}
				description="Listas para agendar"
				tone="sky"
			/>
			<AppointmentMetricCard
				icon={ArchiveBoxIcon}
				label="Elegibles limpieza"
				value={cleanupCount}
				description="Más de 30 días"
				tone="violet"
			/>
		</div>
	);
}

function AppointmentMetricCard({ icon: Icon, label, value, description, tone }) {
	const toneClasses = {
		orange: "border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200",
		blue: "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200",
		green: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200",
		sky: "border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200",
		violet: "border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200",
	};

	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex items-start gap-3">
				<div className={`rounded-xl border p-2 ${toneClasses[tone]}`}>
					<Icon className="size-5" />
				</div>
				<div className="min-w-0">
					<Text className="text-xs text-zinc-500">{label}</Text>
					<div className="mt-1 text-2xl font-semibold text-zinc-950 dark:text-white">
						{value ?? 0}
					</div>
					<Text className="text-xs text-zinc-500">{description}</Text>
				</div>
			</div>
		</div>
	);
}

function Filters({ data, setData, brands }) {
	return (
		<div className="grid gap-4 md:grid-cols-3 lg:grid-cols-4">
			<ListboxFilter
				label="Rango de fechas"
				value={data.date_range}
				onChange={(value) => setData("date_range", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="today" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Citas de hoy</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_7_days" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 7 días</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_15_days" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 15 días</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_30_days" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 30 días</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_60_days" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 60 días</ListboxLabel>
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
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<ClockIcon />
					<ListboxLabel>Solicitadas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Confirmadas</ListboxLabel>
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
				{(brands || []).map((brand) => (
					<ListboxOption
						key={brand.value}
						value={brand.value}
						className="group"
					>
						<BuildingStorefrontIcon />
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
				label="Información para devolución de llamada"
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
	);
}

function LaboratoryAppointmentsList({
	laboratoryAppointments,
	filters,
	filterBadges,
}) {
	if (laboratoryAppointments.data.length === 0) return <EmptyListCard />;

	const pendingAppointments = laboratoryAppointments.data.filter(
		(appointment) => !appointment.confirmed_at && !appointment.admin_is_paid,
	);
	const completedAppointments = laboratoryAppointments.data.filter(
		(appointment) => appointment.confirmed_at || appointment.admin_is_paid,
	);
	const showingConfirmed = filters.completed === "true";
	const groupedAppointments = showingConfirmed
		? completedAppointments
		: pendingAppointments;
	const firstPendingId = pendingAppointments[0]?.id;
	const firstCompletedId = completedAppointments[0]?.id;
	const sectionLabelFor = (appointment) => {
		if (!showingConfirmed && appointment.id === firstPendingId) {
			return "Citas pendientes del periodo";
		}

		if (showingConfirmed && appointment.id === firstCompletedId) {
			return "Citas confirmadas / pagadas";
		}

		return null;
	};
	const renderSectionLabel = (appointment) => {
		const label = sectionLabelFor(appointment);

		if (!label) {
			return null;
		}

		return (
			<div className="mb-3 flex items-center gap-2">
				<span className="h-px w-5 bg-zinc-200 dark:bg-zinc-700" />
				<Text className="text-xs font-semibold uppercase text-zinc-500">
					{label}
				</Text>
			</div>
		);
	};
	const interactionBadgesFor = (appointment) => {
		const badges = [];

		if (appointment.formatted_phone_call_intent_at) {
			badges.push({
				color: "sky",
				label: "Intentó llamar",
			});
		}

		if (appointment.has_left_callback_info) {
			badges.push({
				color: "emerald",
				label: "Pref. llamada",
			});
		}

		if (appointment.admin_has_whatsapp_intent) {
			badges.push({
				color: "famedic-lime",
				label: "WhatsApp",
			});
		}

		return badges;
	};
	const requestAgeBadgeClass = (appointment) => {
		const createdAt = appointment.created_at ? Date.parse(appointment.created_at) : null;

		if (!createdAt) {
			return "!bg-rose-600 !text-white [&>[data-slot=icon]]:!fill-white";
		}

		const ageHours = (Date.now() - createdAt) / (1000 * 60 * 60);

		if (ageHours >= 24) {
			return "!bg-red-700 !text-white shadow-sm shadow-red-500/25 [&>[data-slot=icon]]:!fill-white";
		}

		if (ageHours >= 8) {
			return "!bg-red-600 !text-white shadow-sm shadow-red-500/20 [&>[data-slot=icon]]:!fill-white";
		}

		return "!bg-orange-500 !text-white shadow-sm shadow-orange-500/20 [&>[data-slot=icon]]:!fill-white";
	};

	return (
		<>
			<SearchResultsWithFilters
				paginatedData={laboratoryAppointments}
				filterBadges={filterBadges}
			/>

			<PaginatedTable paginatedData={laboratoryAppointments}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Cita</TableHeader>
							<TableHeader>Flujo / carrito</TableHeader>
							<TableHeader>Última actividad</TableHeader>
							<TableHeader>Interacciones</TableHeader>
							<TableHeader>Laboratorio</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{groupedAppointments.map((laboratoryAppointment) => {
							const interactionBadges = interactionBadgesFor(laboratoryAppointment);

							return (
								<TableRow
									key={laboratoryAppointment.id}
									href={route(
										"admin.laboratory-appointments.show",
										laboratoryAppointment.id,
									)}
									title={`Cita #${laboratoryAppointment.id}`}
									dusk={`editLaboratoryAppointment-${laboratoryAppointment.id}`}
								>
									<TableCell>
										{renderSectionLabel(laboratoryAppointment)}
										<div className="flex items-center gap-2">
											<Avatar
												src={
													laboratoryAppointment
														.customer.user
														.profile_photo_url
												}
												className="size-12"
											/>
											<div>
												{laboratoryAppointment.confirmed_at ? (
													<Badge color="famedic-lime">
														<CheckCircleIcon className="size-3" />
														<span className="text-xs">
															Confirmada{" "}
															{
																laboratoryAppointment.formatted_confirmed_at ||
																laboratoryAppointment.formatted_created_at
															}
														</span>
													</Badge>
												) : (
													<Badge
														color="rose"
														className={requestAgeBadgeClass(laboratoryAppointment)}
													>
														<ClockIcon className="size-3" />
														<span className="text-xs">
															Solicitada{" "}
															{
																laboratoryAppointment.formatted_created_at
															}
														</span>
													</Badge>
												)}
												<Text>
													<Strong>
														{laboratoryAppointment.patient_full_name ||
															laboratoryAppointment
																.customer.user
																.full_name}
													</Strong>
												</Text>
												<Text>
													{
														laboratoryAppointment
															.customer.user.email
													}
												</Text>
											</div>
										</div>
									</TableCell>

									<TableCell>
										<Text>
											{
												laboratoryAppointment.formatted_appointment_date
											}
										</Text>
										{laboratoryAppointment.laboratory_store && (
											<Badge color="slate">
												<BuildingStorefrontIcon className="size-3 fill-famedic-dark dark:fill-famedic-light" />
												<Text>
													<span className="text-xs">
														{
															laboratoryAppointment
																.laboratory_store
																.name
														}
													</span>
												</Text>
											</Badge>
										)}
									</TableCell>

									<TableCell>
										{laboratoryAppointment.admin_checkout_flow?.label ? (
											<Badge color="zinc">
												{laboratoryAppointment.admin_checkout_flow.label}
											</Badge>
										) : (
											<Text className="text-sm text-zinc-400">—</Text>
										)}
										{laboratoryAppointment.cart_id ? (
											<Text className="mt-1 text-xs text-zinc-500">
												Carrito #{laboratoryAppointment.cart_id}
											</Text>
										) : null}
									</TableCell>

									<TableCell>
										{laboratoryAppointment.admin_last_user_activity_human ? (
											<Text className="text-sm">
												{
													laboratoryAppointment.admin_last_user_activity_human
												}
											</Text>
										) : (
											<Text className="text-sm text-zinc-400">—</Text>
										)}
									</TableCell>

									<TableCell>
										{interactionBadges.length > 0 ? (
											<div className="flex flex-wrap gap-1.5">
												{interactionBadges.map((badge) => (
													<Badge key={badge.label} color={badge.color}>
														{badge.label}
													</Badge>
												))}
											</div>
										) : (
											<Text className="text-sm text-zinc-400">
												—
											</Text>
										)}
									</TableCell>

									<TableCell className="text-left">
										<LaboratoryBrandCard
											className="w-40 p-4"
											src={
												"/images/gda/GDA-" +
												laboratoryAppointment.brand.toUpperCase() +
												".png"
											}
										/>
									</TableCell>
								</TableRow>
							);
						})}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}

function PendingFilters({ data, setData, brands }) {
	return (
		<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
			<ListboxFilter
				label="Marca de laboratorio"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				{(brands || []).map((brand) => (
					<ListboxOption
						key={brand.value}
						value={brand.value}
						className="group"
					>
						<BuildingStorefrontIcon />
						<ListboxLabel>{brand.label}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Orden"
				value={data.pending_sort || "priority"}
				onChange={(value) => setData("pending_sort", value)}
			>
				<ListboxOption value="priority" className="group">
					<ClockIcon />
					<ListboxLabel>Prioridad operativa</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="oldest" className="group">
					<ClockIcon />
					<ListboxLabel>Más antiguas primero</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="newest" className="group">
					<ClockIcon />
					<ListboxLabel>Más recientes primero</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>

			<ListboxFilter
				label="Prioridad de actividad"
				value={data.priority_filter || ""}
				onChange={(value) => setData("priority_filter", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="recent" className="group">
					<ClockIcon />
					<ListboxLabel>Actividad reciente</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active_cart" className="group">
					<ClockIcon />
					<ListboxLabel>Con carrito activo</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="without_recent_activity" className="group">
					<ClockIcon />
					<ListboxLabel>Sin actividad reciente</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
		</div>
	);
}

function LaboratoryAppointmentsPendingList({
	laboratoryAppointments,
	filters,
	filterBadges,
	canDeleteOld,
}) {
	const [selectedIds, setSelectedIds] = useState([]);
	const [confirmOpen, setConfirmOpen] = useState(false);
	const [bulkDeleteProcessing, setBulkDeleteProcessing] = useState(false);
	const [bulkDeleteError, setBulkDeleteError] = useState(null);

	const eligibleAppointments = useMemo(
		() =>
			canDeleteOld
				? laboratoryAppointments.data.filter(
						(appointment) => appointment.is_old_delete_eligible === true,
					)
				: [],
		[canDeleteOld, laboratoryAppointments.data],
	);

	const eligibleIds = useMemo(
		() => eligibleAppointments.map((appointment) => appointment.id),
		[eligibleAppointments],
	);

	useEffect(() => {
		setSelectedIds((current) =>
			current.filter((id) => eligibleIds.includes(id)),
		);
	}, [eligibleIds]);

	const selectedCount = selectedIds.length;
	const allEligibleSelected =
		eligibleIds.length > 0 && selectedCount === eligibleIds.length;
	const someEligibleSelected =
		selectedCount > 0 && selectedCount < eligibleIds.length;

	const csrfToken = () =>
		document.querySelector('meta[name="csrf-token"]')?.content || "";

	const toggleAppointmentSelection = (appointmentId, checked) => {
		setSelectedIds((current) => {
			if (checked) {
				return current.includes(appointmentId)
					? current
					: [...current, appointmentId];
			}

			return current.filter((id) => id !== appointmentId);
		});
	};

	const togglePageSelection = (checked) => {
		setSelectedIds(checked ? eligibleIds : []);
	};

	const deleteSelectedAppointments = async () => {
		if (bulkDeleteProcessing || selectedIds.length === 0) {
			return;
		}

		setBulkDeleteProcessing(true);
		setBulkDeleteError(null);

		try {
			const response = await fetch(
				"/admin/laboratory-appointments/bulk-delete",
				{
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						Accept: "application/json",
						"X-Requested-With": "XMLHttpRequest",
						"X-CSRF-TOKEN": csrfToken(),
					},
					body: JSON.stringify({ appointment_ids: selectedIds }),
				},
			);

			if (!response.ok) {
				throw new Error("No se pudieron retirar las citas seleccionadas.");
			}

			const result = await response.json();
			setSelectedIds([]);
			setConfirmOpen(false);

			const shouldGoToPreviousPage =
				result.deleted >= laboratoryAppointments.data.length &&
				laboratoryAppointments.current_page > 1 &&
				laboratoryAppointments.prev_page_url;

			if (shouldGoToPreviousPage) {
				router.get(laboratoryAppointments.prev_page_url, {}, {
					preserveScroll: true,
					preserveState: true,
				});
			} else {
				router.reload({
					only: ["laboratoryAppointments", "pendingCount"],
					preserveScroll: true,
					preserveState: true,
				});
			}
		} catch (error) {
			setBulkDeleteError(
				error?.message || "No se pudieron retirar las citas seleccionadas.",
			);
		} finally {
			setBulkDeleteProcessing(false);
		}
	};

	if (laboratoryAppointments.data.length === 0) {
		return (
			<EmptyListCard
				heading="No hay citas pendientes por atender."
				message="Las nuevas solicitudes aparecerán aquí mientras esperan confirmación."
			/>
		);
	}

	return (
		<>
			<SearchResultsWithFilters
				paginatedData={laboratoryAppointments}
				filterBadges={filterBadges}
			/>

			{selectedCount > 0 && (
				<div className="mb-4 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10">
					<Text className="text-sm font-medium text-amber-950 dark:text-amber-100">
						{selectedCount}{" "}
						{selectedCount === 1
							? "cita seleccionada"
							: "citas seleccionadas"}
					</Text>
					<div className="flex gap-2">
						<Button
							outline
							type="button"
							onClick={() => setSelectedIds([])}
						>
							Limpiar
						</Button>
						<Button
							type="button"
							color="red"
							onClick={() => setConfirmOpen(true)}
						>
							Eliminar seleccionadas
						</Button>
					</div>
				</div>
			)}

			<PaginatedTable paginatedData={laboratoryAppointments}>
				<Table className="[--gutter:theme(spacing.4)] overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<TableHead>
						<TableRow>
							{canDeleteOld && (
								<TableHeader className="w-12">
									<div
										className="flex items-center"
										onClick={(event) => event.stopPropagation()}
									>
										<Checkbox
											color="rose"
											checked={allEligibleSelected}
											indeterminate={someEligibleSelected}
											disabled={eligibleIds.length === 0}
											aria-label="Seleccionar elegibles de esta página"
											onChange={togglePageSelection}
										/>
									</div>
								</TableHeader>
							)}
							<TableHeader>Paciente</TableHeader>
							<TableHeader>Solicitud</TableHeader>
							<TableHeader>Laboratorio</TableHeader>
							<TableHeader>Estado operativo</TableHeader>
							<TableHeader>Contacto</TableHeader>
							<TableHeader className="text-right">
								Acción
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{laboratoryAppointments.data.map((laboratoryAppointment) => (
							<TableRow
								key={laboratoryAppointment.id}
								href={route(
									"admin.laboratory-appointments.show",
									laboratoryAppointment.id,
								)}
								title={`Gestionar cita #${laboratoryAppointment.id}`}
								className="cursor-pointer"
								dusk={`pendingLaboratoryAppointment-${laboratoryAppointment.id}`}
							>
								{canDeleteOld && (
									<TableCell>
										{laboratoryAppointment.is_old_delete_eligible ? (
											<div
												className="relative z-10 flex items-center"
												onClick={(event) => event.stopPropagation()}
											>
												<Checkbox
													color="rose"
													checked={selectedIds.includes(
														laboratoryAppointment.id,
													)}
													aria-label={`Seleccionar cita #${laboratoryAppointment.id}`}
													onChange={(checked) =>
														toggleAppointmentSelection(
															laboratoryAppointment.id,
															checked,
														)
													}
												/>
											</div>
										) : (
											<span className="block size-4" aria-hidden="true" />
										)}
									</TableCell>
								)}

								<TableCell>
									<div className="flex min-w-64 items-start gap-3">
										<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-famedic-light/10 text-sm font-semibold text-famedic-dark dark:bg-famedic-light/20 dark:text-famedic-light">
											{(
												laboratoryAppointment.patient_full_name ||
												laboratoryAppointment.customer?.user?.full_name ||
												"?"
											)
												.split(" ")
												.filter(Boolean)
												.slice(0, 2)
												.map((part) => part[0])
												.join("")
												.toUpperCase()}
										</div>
										<div className="min-w-0">
											<Text>
												<Strong>
													{laboratoryAppointment.patient_full_name ||
														laboratoryAppointment.customer
															?.user?.full_name ||
														"—"}
												</Strong>
											</Text>
											<Text className="text-xs text-zinc-500">
												Cita #{laboratoryAppointment.id}
											</Text>
											{laboratoryAppointment.customer?.user?.email && (
												<Text className="truncate text-xs text-zinc-500">
													{laboratoryAppointment.customer.user.email}
												</Text>
											)}
										</div>
									</div>
								</TableCell>

								<TableCell>
									<div className="space-y-1">
										<Text className="text-sm">
											{laboratoryAppointment.formatted_request_saved_at ||
												laboratoryAppointment.formatted_created_at ||
												"—"}
										</Text>
										<Badge
											color={
												laboratoryAppointment
													.concierge_operational_age
													?.color || "slate"
											}
										>
											{
												laboratoryAppointment
													.concierge_operational_age
													?.label || "—"
											}
										</Badge>
									</div>
								</TableCell>

								<TableCell>
									<div className="flex items-center gap-3">
										<LaboratoryBrandCard
											className="w-20 shrink-0 p-2"
											src={
												"/images/gda/GDA-" +
												laboratoryAppointment.brand.toUpperCase() +
												".png"
											}
										/>
										<div className="min-w-0">
											<Text className="text-sm font-medium">
												{laboratoryAppointment.laboratory_store?.name ||
													"Sucursal pendiente"}
											</Text>
											{laboratoryAppointment.admin_cart_status_label && (
												<Text className="text-xs text-zinc-500">
													{laboratoryAppointment.admin_cart_status_label}
												</Text>
											)}
										</div>
									</div>
								</TableCell>

								<TableCell>
									<div className="space-y-1">
										<Badge
											color={
												laboratoryAppointment
													.concierge_cart_activity_signal
													?.color || "zinc"
											}
										>
											{
												laboratoryAppointment
													.concierge_cart_activity_signal
													?.label || "Sin actividad reciente"
											}
										</Badge>
										{laboratoryAppointment.admin_last_user_activity_human && (
											<Text className="text-xs text-zinc-500">
												Última actividad{" "}
												{
													laboratoryAppointment.admin_last_user_activity_human
												}
											</Text>
										)}
									</div>
								</TableCell>

								<TableCell>
									{laboratoryAppointment.patient_full_phone && (
										<Text className="text-sm">
											{laboratoryAppointment.patient_full_phone}
										</Text>
									)}
									{laboratoryAppointment.customer?.user?.email && (
										<Text className="text-sm text-zinc-500">
											{laboratoryAppointment.customer.user.email}
										</Text>
									)}
									{!laboratoryAppointment.patient_full_phone &&
										!laboratoryAppointment.customer?.user
											?.email && (
											<Text className="text-sm text-zinc-400">
												—
											</Text>
										)}
								</TableCell>

								<TableCell className="text-right">
									<span
										className="relative z-10 inline-flex gap-2"
										onClick={(event) => event.stopPropagation()}
									>
										<Button
											href={route(
												"admin.laboratory-appointments.show",
												laboratoryAppointment.id,
											)}
											outline
											className="whitespace-nowrap"
										>
											<EyeIcon />
											Ver
										</Button>
									</span>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>

			<Dialog open={confirmOpen} onClose={setConfirmOpen} size="lg">
				<DialogTitle>Retirar citas antiguas</DialogTitle>
				<DialogDescription>
					Estás por retirar {selectedCount}{" "}
					{selectedCount === 1 ? "cita pendiente" : "citas pendientes"} con
					más de 30 días de antigüedad.
				</DialogDescription>
				<DialogBody>
					<Text>
						Las citas dejarán de aparecer en Pendientes por atender, pero se
						conservarán mediante soft delete.
					</Text>
					{bulkDeleteError && (
						<Text className="mt-3 text-sm text-red-600 dark:text-red-400">
							{bulkDeleteError}
						</Text>
					)}
				</DialogBody>
				<DialogActions>
					<Button
						plain
						type="button"
						disabled={bulkDeleteProcessing}
						onClick={() => setConfirmOpen(false)}
					>
						Cancelar
					</Button>
					<Button
						type="button"
						color="red"
						disabled={bulkDeleteProcessing || selectedCount === 0}
						onClick={deleteSelectedAppointments}
					>
						Eliminar {selectedCount}{" "}
						{selectedCount === 1 ? "cita" : "citas"}
					</Button>
				</DialogActions>
			</Dialog>
		</>
	);
}
