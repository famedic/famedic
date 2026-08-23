import { useMemo, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
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
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import DateFilter from "@/Components/Filters/DateFilter";
import {
	ArrowPathIcon,
	CloudArrowDownIcon,
} from "@heroicons/react/16/solid";

const FILTER_KEYS = [
	"period",
	"start_date",
	"end_date",
	"status",
	"result_category",
	"failure_origin",
	"failure_certainty",
	"provider",
	"attempt_uuid",
	"support_reference",
	"merchant_reference",
	"provider_order_id",
	"customer_id",
	"customer",
	"has_retries",
	"has_duplicates",
	"has_timeout",
	"has_technical_error",
	"active",
	"terminal",
	"recovered_chain",
	"outcome",
	"recovery_context_type",
	"recovery_context_status",
	"recovery_method",
	"recovery_eligible",
	"recovery_started",
	"authentication_recovered",
	"payment_recovered",
	"selected_retry",
	"selected_different_card",
	"selected_paypal",
	"recovery_confirmation_pending",
	"limit_reached",
	"legacy_only",
	"multiple_get_link",
	"multiple_token_card",
	"tokenization_confirmation_pending",
	"possible_duplicate_operation",
	"excessive_get_status",
];

function badgeColor(tone) {
	return {
		success: "famedic-lime",
		declined: "red",
		cancelled: "amber",
		expired: "orange",
		technical: "rose",
		unknown: "slate",
		active: "sky",
	}[tone] || "zinc";
}

function queryFrom(values) {
	return Object.fromEntries(
		FILTER_KEYS.filter((key) => values[key] !== undefined && values[key] !== "" && values[key] !== null)
			.map((key) => [key, values[key]]),
	);
}

export default function PaymentAuthenticationAttempts({
	attempts,
	filters,
	metrics,
	recoveryMetrics,
	options,
}) {
	const { data, setData, get, processing } = useForm({
		period: filters.period || "7d",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		status: filters.status || "",
		result_category: filters.result_category || "",
		failure_origin: filters.failure_origin || "",
		failure_certainty: filters.failure_certainty || "",
		provider: filters.provider || "",
		attempt_uuid: filters.attempt_uuid || "",
		support_reference: filters.support_reference || "",
		merchant_reference: filters.merchant_reference || "",
		provider_order_id: filters.provider_order_id || "",
		customer_id: filters.customer_id || "",
		customer: filters.customer || "",
		has_retries: filters.has_retries || "",
		has_duplicates: filters.has_duplicates || "",
		has_timeout: filters.has_timeout || "",
		has_technical_error: filters.has_technical_error || "",
		active: filters.active || "",
		terminal: filters.terminal || "",
		recovered_chain: filters.recovered_chain || "",
		outcome: filters.outcome || "",
		recovery_context_type: filters.recovery_context_type || "",
		recovery_context_status: filters.recovery_context_status || "",
		recovery_method: filters.recovery_method || "",
		recovery_eligible: filters.recovery_eligible || "",
		recovery_started: filters.recovery_started || "",
		authentication_recovered: filters.authentication_recovered || "",
		payment_recovered: filters.payment_recovered || "",
		selected_retry: filters.selected_retry || "",
		selected_different_card: filters.selected_different_card || "",
		selected_paypal: filters.selected_paypal || "",
		recovery_confirmation_pending: filters.recovery_confirmation_pending || "",
		limit_reached: filters.limit_reached || "",
		legacy_only: filters.legacy_only || "",
		multiple_get_link: filters.multiple_get_link || "",
		multiple_token_card: filters.multiple_token_card || "",
		tokenization_confirmation_pending: filters.tokenization_confirmation_pending || "",
		possible_duplicate_operation: filters.possible_duplicate_operation || "",
		excessive_get_status: filters.excessive_get_status || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const appliedQuery = useMemo(() => queryFrom(filters), [filters]);

	const filtersCount = useMemo(
		() =>
			FILTER_KEYS.filter((key) => {
				if (["period", "start_date", "end_date", "formatted_start_date", "formatted_end_date", "timezone", "label"].includes(key)) {
					return false;
				}
				return Boolean(filters[key]);
			}).length,
		[filters],
	);

	const updateResults = (e) => {
		e?.preventDefault();
		if (!processing) {
			get(route("admin.payment-authentication-attempts.index"), {
				preserveState: true,
			});
		}
	};

	const applyCard = (overrides) => {
		router.get(
			route("admin.payment-authentication-attempts.index"),
			queryFrom({
				period: filters.period,
				start_date: filters.period === "custom" ? filters.start_date : "",
				end_date: filters.period === "custom" ? filters.end_date : "",
				...overrides,
			}),
			{ preserveState: true },
		);
	};

	const clearFilters = () => {
		router.get(route("admin.payment-authentication-attempts.index"), {}, {
			preserveState: false,
		});
	};

	const exportHref = route(
		"admin.payment-authentication-attempts.export",
		appliedQuery,
	);

	const cards = [
		{ key: "total", label: "Intentos", value: metrics.total, filter: {} },
		{ key: "success", label: "Éxito", value: `${metrics.completed}${metrics.success_rate !== null ? ` · ${metrics.success_rate}%` : ""}`, filter: { status: "completed" } },
		{ key: "declined", label: "Rechazados", value: metrics.declined, filter: { status: "declined" } },
		{ key: "expired_cancelled", label: "Expirados / cancelados", value: metrics.expired_cancelled, filter: { outcome: "expired_cancelled" } },
		{ key: "technical", label: "Errores técnicos", value: metrics.technical_errors, filter: { has_technical_error: "1" } },
		{ key: "unknown", label: "Pendientes / unknown", value: metrics.unknown_pending, filter: { outcome: "unknown_pending" } },
		{ key: "duplicates", label: "Duplicados bloqueados", value: metrics.duplicate_attempts, filter: { has_duplicates: "1" } },
		{ key: "recovered", label: "Reintentos recuperados", value: metrics.recovered_retries, filter: { recovered_chain: "1" } },
	];

	const recoveryCards = recoveryMetrics ? [
		{ key: "eligible", label: "Fallos recuperables", value: recoveryMetrics.eligible_terminal, filter: { recovery_eligible: "1" } },
		{ key: "started", label: "Recuperación iniciada", value: recoveryMetrics.recovery_started, filter: { recovery_started: "1" } },
		{ key: "auth", label: "Tarjeta verificada", value: recoveryMetrics.authentication_recovered, filter: { authentication_recovered: "1" } },
		{ key: "paid", label: "Pagos recuperados", value: recoveryMetrics.payment_recovered, filter: { payment_recovered: "1" } },
		{ key: "paypal", label: "PayPal recuperado", value: recoveryMetrics.paypal_recovered, filter: { payment_recovered: "1", recovery_method: "paypal" } },
		{ key: "idle", label: "Disponible sin acción", value: recoveryMetrics.recovery_available_idle, filter: { recovery_context_status: "recovery_available" } },
		{ key: "pending", label: "Confirmación pendiente", value: recoveryMetrics.confirmation_pending, filter: { recovery_confirmation_pending: "1" } },
		{ key: "legacy", label: "Legacy sin contexto", value: recoveryMetrics.legacy_attempts_without_context, filter: { legacy_only: "1" } },
	] : [];

	return (
		<AdminLayout title="Intentos 3DS">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-1">
						<Heading>Intentos 3DS</Heading>
						<Text>
							Consulta de sólo lectura para diagnosticar autenticaciones
							EfevooPay. Las métricas cuentan intentos, no eventos.
						</Text>
						<Text className="text-sm">
							<Strong>Rango activo:</Strong> {filters.label}
						</Text>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<Button outline href={exportHref}>
							<CloudArrowDownIcon />
							Exportar
						</Button>
						<Button outline onClick={clearFilters}>
							<ArrowPathIcon />
							Limpiar filtros
						</Button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					{cards.map((card) => (
						<button
							key={card.key}
							type="button"
							onClick={() => applyCard(card.filter)}
							className="rounded-xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900"
						>
							<Text className="text-xs uppercase tracking-wide text-zinc-500">
								{card.label}
							</Text>
							<Subheading className="mt-1">{card.value}</Subheading>
						</button>
					))}
				</div>

				{recoveryMetrics && (
					<div className="space-y-3">
						<Subheading>Recuperación</Subheading>
						<Text className="text-xs text-zinc-500">
							Las métricas cuentan contextos únicos, no cada intento.
							Denominador de tasas: {recoveryMetrics.eligible_terminal_denominator_label}.
						</Text>
						<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
							{recoveryCards.map((card) => (
								<button
									key={card.key}
									type="button"
									onClick={() => applyCard(card.filter)}
									className="rounded-xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900"
								>
									<Text className="text-xs uppercase tracking-wide text-zinc-500">{card.label}</Text>
									<Subheading className="mt-1">{card.value}</Subheading>
								</button>
							))}
						</div>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text><Strong>Embudo:</Strong> {recoveryMetrics.funnel?.eligible} elegibles → {recoveryMetrics.funnel?.started} iniciaron</Text>
							<Text className="mt-1">Rama tarjeta: {recoveryMetrics.funnel?.branches?.card_verified} · Rama PayPal pagado: {recoveryMetrics.funnel?.branches?.paypal_paid}</Text>
							<Text className="mt-1 text-xs text-zinc-500">{recoveryMetrics.funnel?.note}</Text>
							<Text className="mt-2">Tasa inicio: {recoveryMetrics.recovery_start_rate ?? "—"}% · Auth: {recoveryMetrics.authentication_recovery_rate ?? "—"}% · Pago: {recoveryMetrics.payment_recovery_rate ?? "—"}%</Text>
						</div>
					</div>
				)}

				<div className="flex flex-wrap items-center gap-3 justify-between">
					<div className="flex flex-wrap items-center gap-3">
						<SearchInput
							value={data.customer}
							onChange={(value) => setData("customer", value)}
							placeholder="Nombre o correo..."
						/>
						<SearchInput
							value={data.support_reference}
							onChange={(value) => setData("support_reference", value)}
							placeholder="Referencia de soporte..."
						/>
						<Button
							outline
							type="button"
							onClick={() => setShowFilters((value) => !value)}
						>
							Filtros
							<FilterCountBadge count={filtersCount} />
						</Button>
						<Button disabled={processing} onClick={updateResults}>
							Actualizar resultados
						</Button>
					</div>
					<Text className="text-xs text-zinc-500">
						Activos: {metrics.active} · Terminales: {metrics.terminal} ·
						Clientes: {metrics.customers_affected}
					</Text>
				</div>

				{showFilters && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
						<div className="grid gap-4 md:grid-cols-3 lg:grid-cols-4">
							<SelectFilter
								label="Periodo"
								value={data.period}
								onChange={(value) => setData("period", value)}
								options={options.periods}
							/>
							{data.period === "custom" && (
								<>
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
								</>
							)}
							<SelectFilter
								label="Estado"
								value={data.status}
								onChange={(value) => setData("status", value)}
								options={options.statuses}
							/>
							<SelectFilter
								label="Categoría"
								value={data.result_category}
								onChange={(value) => setData("result_category", value)}
								options={options.categories}
							/>
							<SelectFilter
								label="Origen"
								value={data.failure_origin}
								onChange={(value) => setData("failure_origin", value)}
								options={options.origins}
							/>
							<SelectFilter
								label="Certeza"
								value={data.failure_certainty}
								onChange={(value) => setData("failure_certainty", value)}
								options={options.certainties}
							/>
							<SelectFilter
								label="Proveedor"
								value={data.provider}
								onChange={(value) => setData("provider", value)}
								options={options.providers}
							/>
							<TextInput
								label="UUID del intento"
								value={data.attempt_uuid}
								onChange={(value) => setData("attempt_uuid", value)}
							/>
							<TextInput
								label="Merchant reference"
								value={data.merchant_reference}
								onChange={(value) => setData("merchant_reference", value)}
							/>
							<TextInput
								label="Provider order ID"
								value={data.provider_order_id}
								onChange={(value) => setData("provider_order_id", value)}
							/>
							<TextInput
								label="Customer ID"
								value={data.customer_id}
								onChange={(value) => setData("customer_id", value)}
							/>
							<SelectFilter
								label="Recovery context type"
								value={data.recovery_context_type}
								onChange={(value) => setData("recovery_context_type", value)}
								options={options.recovery_context_types || []}
							/>
							<SelectFilter
								label="Recovery status"
								value={data.recovery_context_status}
								onChange={(value) => setData("recovery_context_status", value)}
								options={options.recovery_context_statuses || []}
							/>
							<SelectFilter
								label="Recovery method"
								value={data.recovery_method}
								onChange={(value) => setData("recovery_method", value)}
								options={options.recovery_methods || []}
							/>
						</div>
						<div className="flex flex-wrap gap-3">
							<Toggle label="Recovery eligible" checked={data.recovery_eligible === "1"} onChange={(checked) => setData("recovery_eligible", checked ? "1" : "")} />
							<Toggle label="Recovery started" checked={data.recovery_started === "1"} onChange={(checked) => setData("recovery_started", checked ? "1" : "")} />
							<Toggle label="Auth recovered" checked={data.authentication_recovered === "1"} onChange={(checked) => setData("authentication_recovered", checked ? "1" : "")} />
							<Toggle label="Payment recovered" checked={data.payment_recovered === "1"} onChange={(checked) => setData("payment_recovered", checked ? "1" : "")} />
							<Toggle label="Selected retry" checked={data.selected_retry === "1"} onChange={(checked) => setData("selected_retry", checked ? "1" : "")} />
							<Toggle label="Selected different card" checked={data.selected_different_card === "1"} onChange={(checked) => setData("selected_different_card", checked ? "1" : "")} />
							<Toggle label="Selected PayPal" checked={data.selected_paypal === "1"} onChange={(checked) => setData("selected_paypal", checked ? "1" : "")} />
							<Toggle label="Confirmation pending" checked={data.recovery_confirmation_pending === "1"} onChange={(checked) => setData("recovery_confirmation_pending", checked ? "1" : "")} />
							<Toggle label="Limit reached" checked={data.limit_reached === "1"} onChange={(checked) => setData("limit_reached", checked ? "1" : "")} />
							<Toggle label="Legacy only" checked={data.legacy_only === "1"} onChange={(checked) => setData("legacy_only", checked ? "1" : "")} />
							<Toggle
								label="Con reintentos"
								checked={data.has_retries === "1"}
								onChange={(checked) => setData("has_retries", checked ? "1" : "")}
							/>
							<Toggle
								label="Con duplicados"
								checked={data.has_duplicates === "1"}
								onChange={(checked) => setData("has_duplicates", checked ? "1" : "")}
							/>
							<Toggle
								label="Con timeout"
								checked={data.has_timeout === "1"}
								onChange={(checked) => setData("has_timeout", checked ? "1" : "")}
							/>
							<Toggle
								label="Error técnico"
								checked={data.has_technical_error === "1"}
								onChange={(checked) => setData("has_technical_error", checked ? "1" : "")}
							/>
							<Toggle
								label="Activos"
								checked={data.active === "1"}
								onChange={(checked) => setData("active", checked ? "1" : "")}
							/>
							<Toggle
								label="Terminales"
								checked={data.terminal === "1"}
								onChange={(checked) => setData("terminal", checked ? "1" : "")}
							/>
							{(options.efevoopay_operation_filters || []).map((filter) => (
								<Toggle
									key={filter.value}
									label={filter.label}
									checked={data[filter.value] === "1"}
									onChange={(checked) => setData(filter.value, checked ? "1" : "")}
								/>
							))}
						</div>
					</div>
				)}

				<PaginatedTable paginatedData={attempts}>
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Referencia</TableHeader>
								<TableHeader>Resultado</TableHeader>
								<TableHeader>Etapa</TableHeader>
								<TableHeader>Reintento</TableHeader>
								<TableHeader>Recuperación</TableHeader>
								<TableHeader>Llamadas</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{attempts.data.map((attempt) => (
								<TableRow key={attempt.id}>
									<TableCell>
										<Text className="text-xs whitespace-nowrap">
											{attempt.started_at_local}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-sm">
											<Strong>{attempt.customer?.name || "—"}</Strong>
										</Text>
										<Text className="text-xs">{attempt.email}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{attempt.support_reference}</Text>
									</TableCell>
									<TableCell>
										<Badge color={badgeColor(attempt.badge?.tone)}>
											{attempt.badge?.label}
										</Badge>
										<Text className="mt-1 text-xs text-zinc-500">
											{attempt.origin_label}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">{attempt.stage_label}</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs">
											#{attempt.attempt_number}
											{attempt.has_previous_retry ? " · previa" : ""}
											{attempt.has_later_retry ? " · posterior" : ""}
										</Text>
									</TableCell>
									<TableCell>
										<Text className="text-xs leading-snug">
											{attempt.recovery?.summary_label || "—"}
										</Text>
										{attempt.recovery?.selected_intention && (
											<Text className="text-[11px] text-zinc-500">{attempt.recovery.selected_intention}</Text>
										)}
									</TableCell>
									<TableCell>
										<Text className="text-xs whitespace-nowrap">
											{attempt.provider_link_call_count} GL · {attempt.status_poll_call_count} GS · {attempt.tokenization_call_count} TK
										</Text>
										{attempt.possible_duplicate_verification_operation && (
											<Badge color="amber">Posible dup.</Badge>
										)}
									</TableCell>
									<TableCell>
										<Button
											href={route("admin.payment-authentication-attempts.show", attempt.id)}
											outline
											size="sm"
										>
											Ver detalle
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>

				<HelpPanel />
			</div>
		</AdminLayout>
	);
}

function SelectFilter({ label, value, onChange, options }) {
	return (
		<label className="space-y-1 text-sm">
			<Text className="text-sm font-medium">{label}</Text>
			<select
				value={value}
				onChange={(e) => onChange(e.target.value)}
				className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
			>
				<option value="">Todos</option>
				{options.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
		</label>
	);
}

function TextInput({ label, value, onChange }) {
	return (
		<label className="space-y-1 text-sm">
			<Text className="text-sm font-medium">{label}</Text>
			<input
				value={value}
				onChange={(e) => onChange(e.target.value)}
				className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
			/>
		</label>
	);
}

function Toggle({ label, checked, onChange }) {
	return (
		<label className="inline-flex items-center gap-2 text-sm">
			<input
				type="checkbox"
				checked={checked}
				onChange={(e) => onChange(e.target.checked)}
			/>
			{label}
		</label>
	);
}

export function HelpPanel() {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-2">
			<Subheading>Cómo leer los resultados</Subheading>
			<Text><Strong>Rechazado:</Strong> el proveedor reportó que no se completó la autenticación.</Text>
			<Text><Strong>Cancelado:</Strong> se recibió estado de cancelación, pero el origen puede no estar disponible.</Text>
			<Text><Strong>Expirado:</Strong> no se completó dentro del tiempo permitido.</Text>
			<Text><Strong>Timeout:</Strong> FAMEDIC no pudo confirmar la respuesta.</Text>
			<Text><Strong>Error técnico:</Strong> ocurrió una falla de sistema o del proveedor.</Text>
			<Text><Strong>Duplicado bloqueado:</Strong> FAMEDIC evitó una solicitud adicional.</Text>
			<Text><Strong>Unknown:</Strong> falta confirmación suficiente.</Text>
			<Text className="text-xs text-zinc-500">
				Estas etiquetas ayudan a soporte. Un origen desconocido no debe
				atribuirse al banco.
			</Text>
		</div>
	);
}
