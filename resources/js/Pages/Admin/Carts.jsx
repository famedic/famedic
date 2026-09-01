import { useMemo, useState } from "react";
import { Link, useForm } from "@inertiajs/react";
import axios from "axios";
import clsx from "clsx";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ExportDialog from "@/Components/ExportDialog";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import CartDetailDrawer from "@/Components/Admin/Carts/CartDetailDrawer";
import {
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
	CheckCircleIcon,
	ClockIcon,
	CreditCardIcon,
	EyeIcon,
	MagnifyingGlassIcon,
	PhoneIcon,
	ShoppingCartIcon,
	UserIcon,
	XCircleIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import {
	BeakerIcon,
	FunnelIcon,
	ExclamationTriangleIcon,
	ShoppingBagIcon,
} from "@heroicons/react/24/outline";

function MetricCard({
	title,
	value,
	subtitle,
	icon: Icon,
	valueClassName,
	href,
	active = false,
}) {
	const content = (
		<div
			className={clsx(
				"group min-h-[7.25rem] rounded-lg border bg-white p-4 shadow-sm transition focus-within:ring-2 focus-within:ring-famedic-dark/30 dark:bg-zinc-800/90",
				active
					? "border-famedic-dark bg-famedic-dark/5 ring-1 ring-famedic-dark dark:border-famedic-light dark:bg-famedic-light/10 dark:ring-famedic-light"
					: "border-zinc-200 hover:border-zinc-300 hover:shadow-md dark:border-zinc-600/80 dark:hover:border-zinc-500",
				href && "cursor-pointer hover:-translate-y-0.5",
			)}
		>
			<div className="flex items-start justify-between gap-3">
				<div className="min-w-0">
					<p className="text-sm font-semibold leading-snug text-zinc-700 dark:text-zinc-200">
						{title}
					</p>
					<p
						className={clsx(
							"mt-2 text-3xl font-semibold tabular-nums text-zinc-950 dark:text-zinc-50",
							valueClassName,
						)}
					>
						{value}
					</p>
				</div>
				{Icon ? (
					<div
						className={clsx(
							"rounded-lg border p-2 text-zinc-500 transition dark:text-zinc-300",
							active
								? "border-famedic-dark/30 bg-white dark:border-famedic-light/40 dark:bg-zinc-900"
								: "border-zinc-200 bg-zinc-50 group-hover:bg-white dark:border-zinc-700 dark:bg-zinc-900/70",
						)}
					>
						<Icon className="size-5" />
					</div>
				) : null}
			</div>
			{subtitle ? (
				<p className="mt-1.5 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
					{subtitle}
				</p>
			) : null}
		</div>
	);

	if (!href) {
		return content;
	}

	return (
		<Link href={href} preserveState className="block rounded-lg">
			{content}
		</Link>
	);
}

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

function CheckoutTag({ active, label, color }) {
	return (
		<Badge color={active ? color || "green" : "zinc"}>
			<CheckCircleIcon
				className={clsx("size-3.5", !active && "opacity-40")}
			/>
			{label}
		</Badge>
	);
}

function appointmentCheckoutTag(appointment) {
	if (!appointment) {
		return {
			active: false,
			label: "Sin cita",
			color: "zinc",
		};
	}

	if (!appointment.is_confirmed) {
		return {
			active: true,
			label: "Cita por confirmar",
			color: "amber",
		};
	}

	if (!appointment.has_linked_purchase) {
		return {
			active: true,
			label: "Cita confirmada, sin pago",
			color: "violet",
		};
	}

	return {
		active: true,
		label: "Cita confirmada",
		color: "green",
	};
}

function CheckoutSummaryCell({ cart }) {
	if (cart.type !== "lab") {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				No aplica
			</Text>
		);
	}

	const entries = cart.checkout_summary ?? [];

	if (entries.length === 0) {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				Sin avance
			</Text>
		);
	}

	return (
		<div className="space-y-3">
			{entries.map((entry) => {
				const wantsPhoneCall = !!entry.appointment?.has_phone_call_intent;
				const leftCallbackSchedule =
					!!entry.appointment?.has_callback_info;
				const appointmentTag = appointmentCheckoutTag(entry.appointment);

				return (
					<div
						key={entry.id}
						className="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-2 dark:border-zinc-700/70 dark:bg-zinc-900/40"
					>
						{entry.brand_label ? (
							<div className="mb-1.5">
								<Badge color="slate">{entry.brand_label}</Badge>
							</div>
						) : null}
						<div className="flex flex-col items-start gap-1">
							<CheckoutTag
								active={!!entry.patient_name}
								label="Paciente"
							/>
							<CheckoutTag
								active={!!entry.address_short}
								label="Dirección"
							/>
							<CheckoutTag
								active={!!entry.payment_method_label}
								label="Pago"
							/>
							<CheckoutTag
								active={wantsPhoneCall}
								label="Llamó"
								color="sky"
							/>
							<CheckoutTag
								active={leftCallbackSchedule}
								label="Llamada solicitada"
								color="violet"
							/>
							<CheckoutTag
								active={appointmentTag.active}
								label={appointmentTag.label}
								color={appointmentTag.color}
							/>
						</div>
					</div>
				);
			})}
		</div>
	);
}

function stageBadgeColor(tone) {
	return (
		{
			green: "green",
			amber: "amber",
			violet: "violet",
			sky: "sky",
			red: "red",
			slate: "slate",
			zinc: "zinc",
		}[tone] || "zinc"
	);
}

function StageCell({ cart }) {
	const stage = cart.current_stage;

	if (!stage) {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				Checkout incompleto
			</Text>
		);
	}

	return (
		<div className="space-y-1">
			<Badge color={stageBadgeColor(stage.tone)}>{stage.label}</Badge>
			{stage.detail && (
				<Text className="text-xs text-zinc-500 dark:text-zinc-400">
					{stage.detail}
				</Text>
			)}
		</div>
	);
}

function SignalsCell({ cart }) {
	const signals = cart.operational_signals ?? [];

	if (signals.length === 0) {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				Sin señales
			</Text>
		);
	}

	return (
		<div className="flex max-w-[15rem] flex-wrap gap-1.5">
			{signals.map((signal) => (
				<Badge key={signal.key} color={stageBadgeColor(signal.tone)}>
					{signal.label}
					{signal.detail ? ` · ${signal.detail}` : ""}
				</Badge>
			))}
		</div>
	);
}

function AttentionCell({ cart, canViewCartDetails, onOpenDetail }) {
	return (
		<div className="flex justify-end">
			{canViewCartDetails ? (
				<Button
					outline
					type="button"
					onClick={() => onOpenDetail(cart)}
					className="whitespace-nowrap"
				>
					<EyeIcon />
					Ver detalle
				</Button>
			) : (
				<Text className="text-xs">Sin permiso</Text>
			)}
		</div>
	);
}

function QuickFilterBar({ filters, filterUrl, metrics }) {
	const items = [
		{
			key: "all",
			label: "Todos",
			count: metrics?.total,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active:
				!filters.display_status &&
				!filters.operational_filter &&
				!filters.operational_bucket &&
				!filters.payment_status &&
				!filters.checkout_stage &&
				!filters.appointment_filter &&
				!filters.contact_filter,
		},
		{
			key: "no_progress",
			label: "Sin avance",
			count: metrics?.no_progress,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "no_progress",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active: filters.operational_bucket === "no_progress",
		},
		{
			key: "attention",
			label: "Atención requerida",
			count: metrics?.attention_required,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "attention",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active: filters.operational_bucket === "attention",
		},
		{
			key: "payment",
			label: "Pagos",
			count: metrics?.payment_attention,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "payment",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active: filters.operational_bucket === "payment",
		},
		{
			key: "appointment",
			label: "Citas",
			count: metrics?.appointment_attention,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "appointment",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active: filters.operational_bucket === "appointment",
		},
		{
			key: "contact",
			label: "Llamadas",
			count: metrics?.contact_attention,
			href: filterUrl({
				display_status: "",
				operational_filter: "",
				operational_bucket: "contact",
				payment_status: "",
				checkout_stage: "",
				appointment_filter: "",
				contact_filter: "",
			}),
			active: filters.operational_bucket === "contact",
		},
	];

	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/80">
			<div className="flex flex-wrap gap-2">
			{items.map((item) => (
				<Link
					key={item.key}
					href={item.href}
					preserveState
					className={clsx(
						"inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-famedic-dark/30 dark:focus:ring-famedic-light/40",
						item.active
							? "border-famedic-dark bg-famedic-dark text-white dark:border-famedic-light dark:bg-famedic-light dark:text-zinc-900"
							: "border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:border-zinc-500",
					)}
				>
					{item.label}
					{item.count !== undefined && item.count !== null ? (
						<span
							className={clsx(
								"rounded-full px-1.5 py-0.5 text-[11px] tabular-nums",
								item.active
									? "bg-white/20 text-white dark:bg-zinc-950/15 dark:text-zinc-900"
									: "bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200",
							)}
						>
							{item.count}
						</span>
					) : null}
				</Link>
			))}
			</div>
		</div>
	);
}

function dateValue(date) {
	return date.toISOString().slice(0, 10);
}

function FilterChip({ color = "slate", href, children }) {
	return (
		<Link href={href} preserveState className="inline-flex rounded-full">
			<Badge color={color}>
				<span className="inline-flex items-center gap-1">
					{children}
					<XMarkIcon className="size-3.5 opacity-70" />
				</span>
			</Badge>
		</Link>
	);
}

function PeriodBadge({ filters, usingDefaultPeriod }) {
	const label = usingDefaultPeriod ? "Ultimos 7 dias" : null;

	if (!label && !filters.start_date && !filters.end_date) {
		return null;
	}

	if (label) {
		return (
			<Badge color="slate">
				<CalendarDateRangeIcon className="size-4" />
				{label}
			</Badge>
		);
	}

	return null;
}

function SituationCell({ cart }) {
	const stage = cart.current_stage;
	const signals = (cart.operational_signals ?? []).filter(
		(signal) => signal.key !== stage?.key,
	);
	const primarySignal = signals[0];
	const detail =
		stage?.detail ||
		primarySignal?.detail ||
		cart.operational_insight?.recommended_action ||
		null;

	if (!stage && !primarySignal) {
		return <Text className="text-xs text-zinc-500 dark:text-zinc-400">Sin señales</Text>;
	}

	return (
		<div className="min-w-0 space-y-1.5">
			<Badge color={stageBadgeColor(stage?.tone || primarySignal?.tone)}>
				{stage?.label || primarySignal?.label}
			</Badge>
			{detail ? (
				<Text className="line-clamp-2 text-xs leading-5 text-zinc-600 dark:text-zinc-300">
					{detail}
				</Text>
			) : null}
			{primarySignal && primarySignal.key !== stage?.key ? (
				<Text className="line-clamp-1 text-xs text-zinc-500 dark:text-zinc-400">
					{primarySignal.label}
					{primarySignal.detail ? ` · ${primarySignal.detail}` : ""}
				</Text>
			) : null}
			{cart.checkout_flow?.short_label ? (
				<Text className="text-[11px] text-zinc-400 dark:text-zinc-500">
					{cart.checkout_flow.short_label}
				</Text>
			) : null}
		</div>
	);
}

function ResultsToolbar({
	carts,
	filterBadges,
	filters,
	canExport,
	exportUrl,
	usingDefaultPeriod,
}) {
	const total = carts?.total ?? 0;
	const search = filters.search;

	return (
		<div className="flex flex-col gap-3 rounded-lg border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/80 lg:flex-row lg:items-center lg:justify-between">
			<div className="min-w-0 space-y-2">
				<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{total} {total === 1 ? "carrito encontrado" : "carritos encontrados"}
				</p>
				<div className="flex flex-wrap items-center gap-2">
					<PeriodBadge filters={filters} usingDefaultPeriod={usingDefaultPeriod} />
					{filterBadges}
				</div>
				{search && total === 0 ? (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						No encontramos carritos para "{search}".
					</Text>
				) : null}
			</div>
			<ExportDialog
				canExport={canExport}
				filters={filters}
				filterBadges={filterBadges}
				exportUrl={exportUrl}
				title="Descargar carritos"
				className="self-start lg:self-center"
			/>
		</div>
	);
}

function EmptyState({ hasFilters, onClear }) {
	return (
		<div className="rounded-lg border border-dashed border-zinc-300 bg-white px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-800/70">
			<ShoppingCartIcon className="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
			<p className="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
				No encontramos carritos con estos filtros.
			</p>
			<p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
				Ajusta la busqueda o limpia los criterios activos.
			</p>
			{hasFilters ? (
				<div className="mt-4">
					<Button outline type="button" onClick={onClear}>
						Limpiar filtros
					</Button>
				</div>
			) : null}
		</div>
	);
}

function applyDatePreset(setData, preset) {
	const today = new Date();
	const start = new Date(today);
	const end = new Date(today);

	if (preset === "today") {
		// keep today
	} else if (preset === "last_7_days") {
		start.setDate(today.getDate() - 6);
	} else if (preset === "last_30_days") {
		start.setDate(today.getDate() - 29);
	} else if (preset === "this_month") {
		start.setDate(1);
	} else {
		return;
	}

	setData((previous) => ({
		...previous,
		start_date: dateValue(start),
		end_date: dateValue(end),
	}));
}

function CartFilters({ data, setData }) {
	return (
		<div className="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/80">
			<div className="flex flex-wrap gap-2">
				{[
					["today", "Hoy"],
					["last_7_days", "Últimos 7 días"],
					["last_30_days", "Últimos 30 días"],
					["this_month", "Este mes"],
				].map(([preset, label]) => (
					<Button
						key={preset}
						outline
						type="button"
						onClick={() => applyDatePreset(setData, preset)}
					>
						{label}
					</Button>
				))}
			</div>
			<div className="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
			<ListboxFilter
				label="Tipo"
				placeholder="Tipo"
				value={data.type}
				onChange={(value) => setData("type", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="pharmacy" className="group">
					<ShoppingBagIcon />
					<ListboxLabel>Farmacia</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="lab" className="group">
					<BeakerIcon />
					<ListboxLabel>Laboratorio</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Estatus"
				placeholder="Estatus"
				value={data.display_status}
				onChange={(value) => setData("display_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activo</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="abandoned" className="group">
					<XCircleIcon />
					<ListboxLabel>Abandonado</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="completed" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Comprado</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Señal operativa"
				placeholder="Señal operativa"
				value={data.operational_filter}
				onChange={(value) => setData("operational_filter", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="appointment_pending" className="group">
					<ClockIcon />
					<ListboxLabel>Cita pendiente</ListboxLabel>
				</ListboxOption>
				<ListboxOption
					value="appointment_confirmed_pending_payment"
					className="group"
				>
					<CheckCircleIcon />
					<ListboxLabel>Cita confirmada sin pago</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="callback_requested" className="group">
					<ClockIcon />
					<ListboxLabel>Solicitó llamada</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Estado de pago"
				placeholder="Estado de pago"
				value={data.payment_status}
				onChange={(value) => setData("payment_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="declined" className="group">
					<XCircleIcon />
					<ListboxLabel>Rechazado</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="error" className="group">
					<XCircleIcon />
					<ListboxLabel>Error técnico</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="pending" className="group">
					<ClockIcon />
					<ListboxLabel>Pendiente</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="approved" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Aprobado</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Etapa"
				placeholder="Etapa"
				value={data.checkout_stage}
				onChange={(value) => setData("checkout_stage", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="no_progress" className="group">
					<ClockIcon />
					<ListboxLabel>Sin avance</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="patient" className="group">
					<UserIcon />
					<ListboxLabel>Paciente</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="address" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Dirección</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="appointment" className="group">
					<CalendarDateRangeIcon />
					<ListboxLabel>Cita</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="payment" className="group">
					<CreditCardIcon />
					<ListboxLabel>Pago</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="confirmation" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Confirmación</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="completed" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Compra</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Citas"
				placeholder="Citas"
				value={data.appointment_filter}
				onChange={(value) => setData("appointment_filter", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="none" className="group">
					<XCircleIcon />
					<ListboxLabel>Sin cita</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="pending" className="group">
					<ClockIcon />
					<ListboxLabel>Cita pendiente</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="confirmed" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Cita confirmada</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="confirmed_without_payment" className="group">
					<CreditCardIcon />
					<ListboxLabel>Cita confirmada sin pago</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Contacto"
				placeholder="Contacto"
				value={data.contact_filter}
				onChange={(value) => setData("contact_filter", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="callback_requested" className="group">
					<PhoneIcon />
					<ListboxLabel>Solicitó llamada</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="phone_call_intent" className="group">
					<PhoneIcon />
					<ListboxLabel>Intentó llamar</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Cliente"
				placeholder="Cliente"
				value={data.customer_segment}
				onChange={(value) => setData("customer_segment", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="new" className="group">
					<UserIcon />
					<ListboxLabel>Nuevo</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="existing" className="group">
					<UserIcon />
					<ListboxLabel>Existente</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="recurrent" className="group">
					<UserIcon />
					<ListboxLabel>Recurrente</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Marca"
				placeholder="Marca"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="olab" className="group">
					<BeakerIcon />
					<ListboxLabel>OLAB</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="swisslab" className="group">
					<BeakerIcon />
					<ListboxLabel>Swisslab</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="liacsa" className="group">
					<BeakerIcon />
					<ListboxLabel>Liacsa</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="unknown" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Sin identificar</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Monto"
				placeholder="Monto"
				value={data.amount_range}
				onChange={(value) => setData("amount_range", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="lt_1000" className="group">
					<ShoppingCartIcon />
					<ListboxLabel>&lt; $1,000</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="1000_2000" className="group">
					<ShoppingCartIcon />
					<ListboxLabel>$1,000 – $2,000</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="2000_5000" className="group">
					<ShoppingCartIcon />
					<ListboxLabel>$2,000 – $5,000</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="gt_5000" className="group">
					<ShoppingCartIcon />
					<ListboxLabel>&gt; $5,000</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Inactividad"
				placeholder="Inactividad"
				value={data.inactivity_range}
				onChange={(value) => setData("inactivity_range", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="lt_1h" className="group">
					<ClockIcon />
					<ListboxLabel>&lt; 1 hora</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="1_3h" className="group">
					<ClockIcon />
					<ListboxLabel>1–3 horas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="3_24h" className="group">
					<ClockIcon />
					<ListboxLabel>3–24 horas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="1_3d" className="group">
					<ClockIcon />
					<ListboxLabel>1–3 días</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="gt_3d" className="group">
					<ClockIcon />
					<ListboxLabel>&gt; 3 días</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<DateFilter
				label="Desde (actividad)"
				value={data.start_date}
				onChange={(value) => setData("start_date", value)}
			/>
			<DateFilter
				label="Hasta (actividad)"
				value={data.end_date}
				onChange={(value) => setData("end_date", value)}
			/>
			</div>
		</div>
	);
}

export default function Carts({
	carts,
	filters,
	metrics,
	usingDefaultPeriod = false,
	abandonedThresholdMinutes = 30,
	canViewCartDetails = true,
	canExport = false,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		type: filters.type || "",
		display_status: filters.display_status || "",
		operational_filter: filters.operational_filter || "",
		operational_bucket: filters.operational_bucket || "",
		payment_status: filters.payment_status || "",
		checkout_stage: filters.checkout_stage || "",
		appointment_filter: filters.appointment_filter || "",
		contact_filter: filters.contact_filter || "",
		customer_segment: filters.customer_segment || "",
		brand: filters.brand || "",
		amount_range: filters.amount_range || "",
		inactivity_range: filters.inactivity_range || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);
	const [detailOpen, setDetailOpen] = useState(false);
	const [selectedCartId, setSelectedCartId] = useState(null);
	const [cartDetail, setCartDetail] = useState(null);
	const [detailLoading, setDetailLoading] = useState(false);
	const [detailError, setDetailError] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.carts.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.type || "") !== (filters.type || "") ||
			(data.display_status || "") !== (filters.display_status || "") ||
			(data.operational_filter || "") !==
				(filters.operational_filter || "") ||
			(data.operational_bucket || "") !==
				(filters.operational_bucket || "") ||
			(data.payment_status || "") !== (filters.payment_status || "") ||
			(data.checkout_stage || "") !== (filters.checkout_stage || "") ||
			(data.appointment_filter || "") !==
				(filters.appointment_filter || "") ||
			(data.contact_filter || "") !== (filters.contact_filter || "") ||
			(data.customer_segment || "") !==
				(filters.customer_segment || "") ||
			(data.brand || "") !== (filters.brand || "") ||
			(data.amount_range || "") !== (filters.amount_range || "") ||
			(data.inactivity_range || "") !==
				(filters.inactivity_range || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const hasNonDefaultFilters = useMemo(
		() =>
			[
				"search",
				"type",
				"display_status",
				"operational_filter",
				"operational_bucket",
				"payment_status",
				"checkout_stage",
				"appointment_filter",
				"contact_filter",
				"customer_segment",
				"brand",
				"amount_range",
				"inactivity_range",
			].some((key) => filters[key]) ||
			(!usingDefaultPeriod && !!(filters.start_date || filters.end_date)),
		[filters, usingDefaultPeriod],
	);

	const clearFilters = () => {
		get(route("admin.carts.index"), { preserveState: false });
	};

	function filterUrl(overrides = {}) {
		const params = {
			...filters,
			...overrides,
		};
		const query = new URLSearchParams();

		Object.entries(params).forEach(([key, value]) => {
			if (value !== null && value !== undefined && value !== "") {
				query.set(key, value);
			}
		});

		const qs = query.toString();
		return qs ? `${route("admin.carts.index")}?${qs}` : route("admin.carts.index");
	}

	const loadCartDetail = async (cartId) => {
		setDetailLoading(true);
		setDetailError(false);

		try {
			const response = await axios.get(
				route("admin.carts.show", { cart: cartId }),
				{
					headers: {
						Accept: "application/json",
					},
				},
			);
			setCartDetail(response.data?.data ?? null);
		} catch {
			setDetailError(true);
		} finally {
			setDetailLoading(false);
		}
	};

	const openCartDetail = (cart) => {
		setSelectedCartId(cart.id);
		setCartDetail(null);
		setDetailError(false);
		setDetailOpen(true);
		loadCartDetail(cart.id);
	};

	const retryCartDetail = () => {
		if (selectedCartId) {
			loadCartDetail(selectedCartId);
		}
	};

	const filterBadges = useMemo(() => {
		const badges = [];
		if (filters.search) {
			badges.push(
				<FilterChip key="s" color="sky" href={filterUrl({ search: "" })}>
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</FilterChip>,
			);
		}
		if (filters.type === "pharmacy") {
			badges.push(
				<FilterChip key="t" color="slate" href={filterUrl({ type: "" })}>
					<ShoppingBagIcon className="size-4" />
					Farmacia
				</FilterChip>,
			);
		} else if (filters.type === "lab") {
			badges.push(
				<FilterChip key="t" color="slate" href={filterUrl({ type: "" })}>
					<BeakerIcon className="size-4" />
					Laboratorio
				</FilterChip>,
			);
		}
		if (filters.display_status) {
			const { label } = statusBadge(filters.display_status);
			badges.push(
				<FilterChip
					key="d"
					color="famedic-lime"
					href={filterUrl({ display_status: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.operational_filter) {
			const label =
				{
					appointment_pending: "Cita pendiente",
					appointment_confirmed_pending_payment:
						"Cita confirmada sin pago",
					callback_requested: "Solicitó llamada",
				}[filters.operational_filter] || filters.operational_filter;
			badges.push(
				<FilterChip
					key="op"
					color="violet"
					href={filterUrl({ operational_filter: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.operational_bucket) {
			const label =
				{
					attention: "Atención requerida",
					payment: "Pagos",
					appointment: "Citas",
					contact: "Llamadas",
				}[filters.operational_bucket] || filters.operational_bucket;
			badges.push(
				<FilterChip
					key="bucket"
					color="famedic-lime"
					href={filterUrl({ operational_bucket: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.payment_status) {
			const label =
				{
					declined: "Pago rechazado",
					error: "Error técnico",
					pending: "Intento pendiente",
					approved: "Pago aprobado",
				}[filters.payment_status] || filters.payment_status;
			badges.push(
				<FilterChip
					key="pay"
					color="red"
					href={filterUrl({ payment_status: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.checkout_stage) {
			const label =
				{
					no_progress: "Sin avance",
					patient: "Paciente",
					address: "Direccion",
					appointment: "Cita",
					payment: "Pago",
					confirmation: "Confirmacion",
					completed: "Completado",
				}[filters.checkout_stage] || filters.checkout_stage;
			badges.push(
				<FilterChip
					key="stage"
					color="sky"
					href={filterUrl({ checkout_stage: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.appointment_filter) {
			const label =
				{
					none: "Sin cita",
					pending: "Cita pendiente",
					confirmed: "Cita confirmada",
					confirmed_without_payment: "Cita confirmada sin pago",
				}[filters.appointment_filter] || filters.appointment_filter;
			badges.push(
				<FilterChip
					key="appt"
					color="violet"
					href={filterUrl({ appointment_filter: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.contact_filter) {
			const label =
				{
					callback_requested: "Solicito llamada",
					phone_call_intent: "Intencion telefonica",
				}[filters.contact_filter] || filters.contact_filter;
			badges.push(
				<FilterChip
					key="contact"
					color="amber"
					href={filterUrl({ contact_filter: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.customer_segment) {
			const label =
				{
					new: "Cliente nuevo",
					existing: "Cliente existente",
					recurrent: "Cliente recurrente",
				}[filters.customer_segment] || filters.customer_segment;
			badges.push(
				<FilterChip
					key="segment"
					color="slate"
					href={filterUrl({ customer_segment: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.brand) {
			const label =
				{
					olab: "Olab",
					swisslab: "Swisslab",
					liacsa: "Liacsa",
					azteca: "Azteca",
					jenner: "Jenner",
					unknown: "Sin marca",
				}[filters.brand] || filters.brand;
			badges.push(
				<FilterChip key="brand" color="slate" href={filterUrl({ brand: "" })}>
					{label}
				</FilterChip>,
			);
		}
		if (filters.amount_range) {
			const label =
				{
					lt_1000: "Menos de $1,000",
					"1000_2000": "$1,000 - $2,000",
					"2000_5000": "$2,000 - $5,000",
					gt_5000: "Mas de $5,000",
				}[filters.amount_range] || filters.amount_range;
			badges.push(
				<FilterChip
					key="amount"
					color="slate"
					href={filterUrl({ amount_range: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (filters.inactivity_range) {
			const label =
				{
					lt_1h: "Menos de 1h",
					"1_3h": "1-3h",
					"3_24h": "3-24h",
					"1_3d": "1-3d",
					gt_3d: "Mas de 3d",
				}[filters.inactivity_range] || filters.inactivity_range;
			badges.push(
				<FilterChip
					key="inactive"
					color="slate"
					href={filterUrl({ inactivity_range: "" })}
				>
					{label}
				</FilterChip>,
			);
		}
		if (!usingDefaultPeriod && (filters.start_date || filters.end_date)) {
			badges.push(
				<FilterChip
					key="r"
					color="slate"
					href={filterUrl({ start_date: "", end_date: "" })}
				>
					<CalendarDateRangeIcon className="size-4" />
					{filters.start_date || "…"} — {filters.end_date || "…"}
				</FilterChip>,
			);
		}
		return badges;
	}, [filters, usingDefaultPeriod]);

	const filtersCount = [
		"search",
		"type",
		"display_status",
		"operational_filter",
		"operational_bucket",
		"payment_status",
		"checkout_stage",
		"appointment_filter",
		"contact_filter",
		"customer_segment",
		"brand",
		"amount_range",
		"inactivity_range",
	].filter((key) => filters[key]).length + (!usingDefaultPeriod && (filters.start_date || filters.end_date) ? 1 : 0);

	return (
		<AdminLayout title="Carritos">
			<div className="space-y-5">
				<div>
					<Heading>Centro de recuperacion de carritos</Heading>
					<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
						Monitorea abandono, citas, pagos y atencion requerida.
					</Text>
				</div>

				<form className="space-y-3" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por usuario o correo..."
						/>
						<div className="flex items-center justify-end gap-2">
							<Button
								href={route("admin.carts.dashboard")}
								outline
								className="w-full sm:w-auto"
							>
								Dashboard
							</Button>
							<Button
								outline
								type="button"
								className="w-full"
								onClick={() => setShowFilters((v) => !v)}
							>
								<FunnelIcon />
								Filtros
								{filtersCount > 0 ? (
									<FilterCountBadge count={filtersCount} />
								) : null}
							</Button>
							{hasNonDefaultFilters ? (
								<Button
									href={route("admin.carts.index")}
									outline
									className="w-full sm:w-auto"
								>
									Limpiar filtros
								</Button>
							) : null}
						</div>
					</div>

					{showFilters && (
						<CartFilters data={data} setData={setData} />
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton type="submit" processing={processing} />
						</div>
					)}
				</form>

				{metrics && (
					<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
						<MetricCard
							title="Activos"
							value={metrics.active}
							subtitle="Con actividad reciente"
							icon={CheckCircleIcon}
							href={filterUrl({
								display_status: "active",
								operational_filter: "",
								operational_bucket: "",
								payment_status: "",
							})}
							active={filters.display_status === "active"}
						/>
						<MetricCard
							title="Abandonados"
							value={metrics.abandoned}
							subtitle={`Sin actividad por ${abandonedThresholdMinutes}+ min`}
							icon={XCircleIcon}
							valueClassName="text-red-600 dark:text-red-300"
							href={filterUrl({
								display_status: "abandoned",
								operational_filter: "",
								operational_bucket: "",
								payment_status: "",
							})}
							active={filters.display_status === "abandoned"}
						/>
						<MetricCard
							title="Comprados"
							value={metrics.completed}
							subtitle="Carritos completados"
							icon={ShoppingCartIcon}
							valueClassName="text-blue-600 dark:text-sky-300"
							href={filterUrl({
								display_status: "completed",
								operational_filter: "",
								operational_bucket: "",
								payment_status: "",
							})}
							active={filters.display_status === "completed"}
						/>
						<MetricCard
							title="Espera confirmacion"
							value={metrics.appointment_pending_confirmation ?? 0}
							subtitle="Citas por confirmar"
							icon={CalendarDateRangeIcon}
							valueClassName="text-amber-600 dark:text-amber-300"
							href={filterUrl({
								display_status: "",
								operational_filter: "appointment_pending",
								operational_bucket: "",
								payment_status: "",
							})}
							active={
								filters.operational_filter ===
								"appointment_pending"
							}
						/>
						<MetricCard
							title="Cita sin pago"
							value={metrics.appointment_confirmed_pending_payment ?? 0}
							subtitle="Pendientes de pago"
							icon={CreditCardIcon}
							valueClassName="text-violet-600 dark:text-violet-300"
							href={filterUrl({
								display_status: "",
								operational_filter:
									"appointment_confirmed_pending_payment",
								operational_bucket: "",
								payment_status: "",
							})}
							active={
								filters.operational_filter ===
								"appointment_confirmed_pending_payment"
							}
						/>
						<MetricCard
							title="Conversion"
							subtitle="Comprados / comprados + abandono"
							icon={ExclamationTriangleIcon}
							value={
								metrics.conversion_percent != null
									? `${metrics.conversion_percent}%`
									: "—"
							}
							valueClassName="text-famedic-darker dark:text-famedic-lime"
						/>
					</div>
				)}

				<QuickFilterBar
					filters={filters}
					filterUrl={filterUrl}
					metrics={metrics}
				/>

				<ResultsToolbar
					carts={carts}
					filterBadges={filterBadges}
					filters={filters}
					canExport={canExport}
					exportUrl={route("admin.carts.export")}
					usingDefaultPeriod={usingDefaultPeriod}
				/>

				{carts.data.length === 0 ? (
					<EmptyState hasFilters={hasNonDefaultFilters} onClear={clearFilters} />
				) : (
				<PaginatedTable paginatedData={carts}>
					<Table wrap tableClassName="w-full table-fixed">
						<colgroup>
							<col className="w-[27%]" />
							<col className="w-[18%]" />
							<col className="w-[29%]" />
							<col className="w-[14%]" />
							<col className="w-[12%]" />
						</colgroup>
						<TableHead>
							<TableRow>
								<TableHeader className="py-2.5">Usuario</TableHeader>
								<TableHeader className="py-2.5">Carrito</TableHeader>
								<TableHeader className="py-2.5">Situacion</TableHeader>
								<TableHeader className="py-2.5">Actividad</TableHeader>
								<TableHeader className="py-2.5 text-right">Accion</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{carts.data.map((cart) => {
								const b = statusBadge(cart.display_status);
								return (
									<TableRow
										key={cart.id}
										className={clsx(
											"transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/70",
											detailOpen &&
												selectedCartId === cart.id &&
												"border-l-4 border-l-famedic-dark bg-famedic-dark/5 ring-1 ring-inset ring-famedic-dark/20 dark:border-l-famedic-light dark:bg-famedic-light/10 dark:ring-famedic-light/30",
										)}
									>
										<TableCell className="py-4 align-top">
											<div className="min-w-0 space-y-2">
												{cart.user ? (
													<div className="min-w-0 space-y-1">
														<Text className="truncate !text-zinc-950 dark:!text-white">
															<Strong>
																{cart.user.full_name ||
																	cart.user.email}
															</Strong>
														</Text>
														{cart.user.email && (
															<Text className="truncate text-xs" title={cart.user.email}>
																{cart.user.email}
															</Text>
														)}
														{cart.customer_history && (
															<Badge
																color={
																	cart.customer_history.segment ===
																	"recurrent"
																		? "green"
																		: cart.customer_history
																					.segment === "existing"
																			? "sky"
																			: "zinc"
																}
															>
																{cart.customer_history.label}
															</Badge>
														)}
													</div>
												) : (
													<Text className="text-sm">—</Text>
												)}
											</div>
										</TableCell>
									<TableCell className="py-4 align-top">
										<div className="min-w-0 space-y-1">
											<div className="flex min-w-0 items-center gap-1.5 text-sm font-medium text-zinc-950 dark:text-zinc-100">
												<ShoppingCartIcon className="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
												<span className="truncate">
													{cart.type === "lab"
														? cart.cart_summary?.brand_label || "Sin marca"
														: cart.type_label}
												</span>
												<span className="shrink-0 text-xs font-medium text-zinc-500">
													#{cart.id}
												</span>
											</div>
											<Text className="text-xs text-zinc-500 dark:text-zinc-400">
												{cart.cart_summary?.items_label ??
													`${cart.items_count} items`}
											</Text>
											<Text className="text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
												{cart.total_formatted} MXN
											</Text>
										</div>
									</TableCell>
										<TableCell className="py-4 align-top">
											<SituationCell cart={cart} />
										</TableCell>
										<TableCell className="py-4 align-top">
											<div className="space-y-0.5">
												{cart.display_status === "abandoned" ? (
													<Badge color={b.color}>{b.label}</Badge>
												) : null}
												{cart.inactive_for_label ? (
													<Text
														className={clsx(
															"text-sm font-medium tabular-nums",
															cart.display_status === "abandoned"
																? "text-red-600 dark:text-red-300"
																: "text-zinc-900 dark:text-zinc-100",
														)}
													>
														Hace {cart.inactive_for_label}
													</Text>
												) : cart.display_status === "completed" ? (
													<Text className="text-xs text-zinc-500 dark:text-zinc-400">
														Compra registrada
													</Text>
												) : null}
												<div className="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
													<ClockIcon className="size-4 shrink-0" />
													<span>
														{cart.updated_at_human || "—"}
													</span>
												</div>
											</div>
										</TableCell>
										<TableCell className="py-4 align-top">
											<AttentionCell
												cart={cart}
												canViewCartDetails={canViewCartDetails}
												onOpenDetail={openCartDetail}
											/>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</PaginatedTable>
				)}

				<CartDetailDrawer
					open={detailOpen}
					detail={cartDetail}
					loading={detailLoading}
					error={detailError}
					onClose={() => setDetailOpen(false)}
					onRetry={retryCartDetail}
				/>
			</div>
		</AdminLayout>
	);
}
