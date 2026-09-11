import * as Headless from "@headlessui/react";
import {
	ArrowPathIcon,
	BeakerIcon,
	CalendarDaysIcon,
	ChevronDownIcon,
	CreditCardIcon,
	PhoneIcon,
	ShoppingCartIcon,
	UserIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	displayStatusLabel,
	paymentHistorySummary,
} from "@/lib/cartDrawerDisplay";

const STATUS_TONE = {
	approved: "green",
	completed: "green",
	confirmed: "green",
	synced: "green",
	declined: "red",
	error: "red",
	failed: "red",
	pending: "amber",
	processing: "amber",
	current: "amber",
	skipped: "zinc",
};

const JOURNEY_STYLE = {
	completed: {
		wrap: "border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200",
		mark: "OK",
	},
	current: {
		wrap: "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200",
		mark: "...",
	},
	failed: {
		wrap: "border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200",
		mark: "X",
	},
	pending: {
		wrap: "border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300",
		mark: "o",
	},
};

function toneColor(tone) {
	return (
		{
			green: "green",
			amber: "amber",
			red: "red",
			violet: "violet",
			sky: "sky",
			slate: "slate",
			zinc: "zinc",
		}[tone] || "zinc"
	);
}

function statusTone(status) {
	return STATUS_TONE[status] || "zinc";
}

function formatDateTime(value, fallback) {
	if (!value) {
		return fallback || null;
	}

	const parsed = new Date(value);
	if (Number.isNaN(parsed.getTime())) {
		return fallback || value;
	}

	return new Intl.DateTimeFormat("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	}).format(parsed);
}

function formatTime(value, fallback) {
	if (!value) {
		return fallback || null;
	}

	const parsed = new Date(value);
	if (Number.isNaN(parsed.getTime())) {
		return fallback || value;
	}

	return new Intl.DateTimeFormat("es-MX", {
		hour: "numeric",
		minute: "2-digit",
	}).format(parsed);
}

function formatTimeWithSeconds(value, fallback) {
	if (!value) {
		return fallback || null;
	}

	const parsed = new Date(value);
	if (Number.isNaN(parsed.getTime())) {
		return fallback || value;
	}

	return new Intl.DateTimeFormat("es-MX", {
		hour: "numeric",
		minute: "2-digit",
		second: "2-digit",
	}).format(parsed);
}

function Section({ title, icon: Icon, children, flush = false }) {
	return (
		<section
			className={`border-b border-zinc-200 last:border-0 dark:border-zinc-700 ${flush ? "pb-3" : "space-y-2.5 pb-4"}`}
		>
			<div className="flex items-center gap-2">
				{Icon ? <Icon className="size-4 text-zinc-400" /> : null}
				<h3 className="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">
					{title}
				</h3>
			</div>
			{children}
		</section>
	);
}

function Field({ label, value, className = "" }) {
	return (
		<div className={className}>
			<p className="text-[10px] uppercase tracking-wide text-zinc-400">
				{label}
			</p>
			<p className="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-100">
				{value || "-"}
			</p>
		</div>
	);
}

function MiniLink({ href, children }) {
	if (!href) {
		return null;
	}

	return (
		<Button href={href} outline className="px-2.5 py-1 text-xs">
			{children}
		</Button>
	);
}

function SummaryStrip({ cart, finalPayment, operationalInsight }) {
	const message = finalPayment
		? ["Compra completada", finalPayment.method_label, finalPayment.amount]
				.filter(Boolean)
				.join(" | ")
		: operationalInsight?.tone &&
			  !["green", "slate", "zinc"].includes(operationalInsight.tone)
			? [operationalInsight.label, "atención requerida"]
					.filter(Boolean)
					.join(" | ")
			: cart?.status_summary || cart?.display_status_label;

	if (!message) {
		return null;
	}

	return (
		<div className="border-t border-zinc-200 bg-zinc-50 px-5 py-2 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-200">
			{message}
		</div>
	);
}

function Journey({ steps = [] }) {
	return (
		<div className="grid grid-cols-3 gap-1.5">
			{steps.map((step) => {
				const style =
					JOURNEY_STYLE[step.state] || JOURNEY_STYLE.pending;

				return (
					<div
						key={step.key}
						className={`min-h-[4.25rem] rounded-md border px-2.5 py-2 ${style.wrap}`}
					>
						<div className="flex items-center justify-between gap-2">
							<p className="text-xs font-semibold leading-4">
								{step.label}
							</p>
							<span className="text-sm font-semibold leading-none">
								{style.mark}
							</span>
						</div>
						<p className="mt-1 line-clamp-2 text-[11px] leading-4 opacity-80">
							{step.detail}
						</p>
					</div>
				);
			})}
		</div>
	);
}

function DisclosureSection({
	title,
	icon: Icon,
	summary,
	defaultOpen = false,
	children,
}) {
	return (
		<Headless.Disclosure
			as="section"
			defaultOpen={defaultOpen}
			className="border-b border-zinc-200 pb-2 last:border-0 dark:border-zinc-700"
		>
			{({ open }) => (
				<>
					<Headless.DisclosureButton className="flex w-full items-center justify-between gap-3 rounded-md px-1 py-2 text-left text-sm focus:outline-none focus:ring-2 focus:ring-famedic-dark/30 dark:focus:ring-famedic-light/40">
						<span className="flex min-w-0 items-center gap-2">
							{Icon ? (
								<Icon className="size-4 shrink-0 text-zinc-400" />
							) : null}
							<span className="truncate text-[11px] font-semibold uppercase tracking-wide text-zinc-500">
								{title}
							</span>
						</span>
						<span className="flex shrink-0 items-center gap-2">
							{summary ? (
								<span className="text-xs text-zinc-500 dark:text-zinc-400">
									{summary}
								</span>
							) : null}
							<ChevronDownIcon
								className={`size-4 text-zinc-400 transition-transform ${open ? "rotate-180" : ""}`}
							/>
						</span>
					</Headless.DisclosureButton>
					<Headless.DisclosurePanel className="px-1 pb-3 pt-1">
						{children}
					</Headless.DisclosurePanel>
				</>
			)}
		</Headless.Disclosure>
	);
}

function PaymentTimeline({ items = [] }) {
	if (!items.length) {
		return (
			<p className="text-sm text-zinc-500">
				Sin historial de pagos confiable.
			</p>
		);
	}

	return (
		<div className="space-y-3">
			{items.map((item) => {
				const isFinal = item.type === "final_payment";
				const marker =
					isFinal || ["approved", "completed"].includes(item.status)
						? "OK"
						: "...";

				return (
					<div
						key={item.id}
						className="grid grid-cols-[2.75rem_1fr] gap-3"
					>
						<div className="text-right text-xs tabular-nums text-zinc-500">
							{formatTime(
								item.occurred_at,
								item.occurred_at_human,
							)}
						</div>
						<div className="relative border-l border-zinc-200 pb-1 pl-3 dark:border-zinc-700">
							<span
								className={`absolute -left-[7px] top-0 flex size-3.5 items-center justify-center rounded-full text-[9px] ${isFinal ? "bg-green-600 text-white" : "bg-zinc-300 text-zinc-700 dark:bg-zinc-600 dark:text-zinc-100"}`}
							>
								{marker}
							</span>
							<div className="flex flex-wrap items-center gap-2">
								<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{item.gateway_label ||
										item.gateway ||
										"Pago"}
								</p>
								{isFinal ? (
									<Badge color="green">Pago final</Badge>
								) : null}
								<Badge color={statusTone(item.status)}>
									{displayStatusLabel(
										item.status_label || item.status,
									)}
								</Badge>
							</div>
							{item.processor_code || item.processor_message ? (
								<p className="mt-1 text-xs text-zinc-500">
									{[
										item.processor_code
											? `Código ${item.processor_code}`
											: null,
										item.processor_message,
									]
										.filter(Boolean)
										.join(" | ")}
								</p>
							) : null}
						</div>
					</div>
				);
			})}
		</div>
	);
}

function AppointmentJourney({ steps = [], appointment }) {
	if (!steps.length) {
		return <p className="text-sm text-zinc-500">Sin journey de cita.</p>;
	}

	return (
		<div className="space-y-3">
			{appointment?.appointment_date_human || appointment?.store_name ? (
				<div className="rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-900 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-100">
					<p className="font-semibold">
						{appointment?.appointment_date_human ||
							"Cita programada"}
					</p>
					{appointment?.store_name ? (
						<p className="mt-0.5 text-xs opacity-80">
							{appointment.store_name}
						</p>
					) : null}
				</div>
			) : null}
			<div className="space-y-0">
				{steps.map((step, index) => {
					const style =
						JOURNEY_STYLE[step.state] || JOURNEY_STYLE.pending;

					return (
						<div
							key={step.key}
							className="grid grid-cols-[1.25rem_1fr] gap-2"
						>
							<div className="flex flex-col items-center">
								<span
									className={`flex size-5 items-center justify-center rounded-full border text-xs ${style.wrap}`}
								>
									{style.mark}
								</span>
								{index < steps.length - 1 ? (
									<span className="h-5 w-px bg-zinc-200 dark:bg-zinc-700" />
								) : null}
							</div>
							<div className="pb-2">
								<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{step.label}
								</p>
								<p className="text-xs text-zinc-500">
									{step.detail}
								</p>
							</div>
						</div>
					);
				})}
			</div>
		</div>
	);
}

function SimpleTimeline({
	items = [],
	empty = "Sin datos",
	showSeconds = false,
}) {
	if (!items.length) {
		return <p className="text-sm text-zinc-500">{empty}</p>;
	}

	return (
		<div className="space-y-2.5">
			{items.map((item) => (
				<div
					key={item.id}
					className="grid grid-cols-[3.25rem_1fr] gap-3 text-sm"
				>
					<div className="text-right text-xs tabular-nums text-zinc-500">
						{showSeconds
							? formatTimeWithSeconds(
									item.occurred_at,
									item.occurred_at_human_with_seconds ||
										item.occurred_at_human,
								)
							: formatTime(
									item.occurred_at,
									item.occurred_at_human,
								)}
					</div>
					<div className="border-l border-zinc-200 pl-3 dark:border-zinc-700">
						<div className="flex flex-wrap items-center gap-2">
							<p className="font-medium text-zinc-900 dark:text-zinc-50">
								{item.label || item.event}
							</p>
							{item.status ? (
								<Badge color={statusTone(item.status)}>
									{displayStatusLabel(item.status)}
								</Badge>
							) : null}
							{item.confidence === "customer_legacy" ? (
								<Badge color="zinc">Evento historico</Badge>
							) : null}
						</div>
						{item.message ? (
							<p className="mt-1 text-xs text-zinc-500">
								{item.message}
							</p>
						) : null}
						{item.client ? (
							<p className="mt-1 text-xs text-zinc-500">
								{clientLine(item.client)}
							</p>
						) : null}
						{item.metadata && Object.keys(item.metadata).length ? (
							<p className="mt-1 break-words text-xs text-zinc-500">
								{Object.entries(item.metadata)
									.map(([key, value]) => `${key}: ${value}`)
									.join(" | ")}
							</p>
						) : null}
					</div>
				</div>
			))}
		</div>
	);
}

function ActiveCampaignSummary(activeCampaign) {
	const items = activeCampaign?.items || [];
	if (!items.length) {
		return "Sin datos";
	}

	if (items.every((item) => item.confidence === "customer_legacy")) {
		return `${items.length} evento${items.length === 1 ? "" : "s"}`;
	}

	const failed = items.filter((item) => item.status === "failed").length;
	if (failed) {
		return `${failed} fallo${failed === 1 ? "" : "s"}`;
	}

	const synced = items.filter((item) => item.status === "synced").length;
	return synced
		? "Sincronizado"
		: `${items.length} registro${items.length === 1 ? "" : "s"}`;
}

function CustomerSummary({ customer, history }) {
	const count = Number(
		customer?.previous_purchases_count ||
			history?.previous_purchases_count ||
			0,
	);
	const segment =
		customer?.segment_label?.replace(/^Cliente\s+/i, "") || "Cliente";
	const value = history?.historical_value_formatted;

	return [segment, `${count} compra${count === 1 ? "" : "s"}`, value]
		.filter(Boolean)
		.join(" | ");
}

function clientLine(client) {
	if (!client) {
		return null;
	}

	return [client.device_label, client.os, client.browser]
		.filter(Boolean)
		.join(" | ");
}

function locationLine(location) {
	if (!location) {
		return null;
	}

	return [location.city, location.state, location.country]
		.filter(Boolean)
		.join(", ");
}

function sourceLabel(source) {
	return source === "activecampaign" ? "ActiveCampaign" : source;
}

function SessionContextSummary(context) {
	if (!context?.has_data) {
		return null;
	}

	const labels = context.devices_seen_labels || [];
	if (labels.length) {
		return labels.join(" -> ");
	}

	return (
		context.last_device?.device_label ||
		(context.location ? "Ubicacion aproximada" : null)
	);
}

function SessionContext({ context }) {
	if (!context?.has_data) {
		return null;
	}

	const last = context.last_device;
	const timeline = context.timeline || [];
	const location = context.location;
	const formattedLocation = locationLine(location);

	return (
		<div className="space-y-3">
			{last ? (
				<div className="grid grid-cols-3 gap-2 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/60">
					<Field
						label="Ultimo dispositivo"
						value={last.device_label}
					/>
					<Field label="Sistema" value={last.os} />
					<Field label="Navegador" value={last.browser} />
				</div>
			) : null}
			{location ? (
				<div className="space-y-1.5 rounded-md border border-zinc-200 px-3 py-2.5 text-sm dark:border-zinc-700">
					<p className="text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
						Ubicacion aproximada
					</p>
					{formattedLocation ? (
						<p className="text-zinc-800 dark:text-zinc-100">
							{formattedLocation}
						</p>
					) : null}
					{location.timezone ? (
						<p className="text-xs text-zinc-500 dark:text-zinc-400">
							{location.timezone}
						</p>
					) : null}
					<div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
						<span>Fuente: {sourceLabel(location.source)}</span>
						{location.cached_at_human ? (
							<span>Actualizado {location.cached_at_human}</span>
						) : null}
					</div>
				</div>
			) : null}
			{timeline.length ? (
				<div className="space-y-2.5">
					{timeline.map((item) => (
						<div
							key={item.id}
							className="grid grid-cols-[3.25rem_1fr] gap-3 text-sm"
						>
							<div className="text-right text-xs tabular-nums text-zinc-500">
								{formatTime(
									item.occurred_at,
									item.occurred_at_human,
								)}
							</div>
							<div className="border-l border-zinc-200 pl-3 dark:border-zinc-700">
								<p className="font-medium text-zinc-900 dark:text-zinc-50">
									{item.label || item.event}
								</p>
								<p className="mt-1 text-xs text-zinc-500">
									{clientLine(item.client)}
								</p>
							</div>
						</div>
					))}
				</div>
			) : null}
		</div>
	);
}

function WebActivityTimeline({ items = [] }) {
	if (!items.length) {
		return null;
	}

	return (
		<div className="space-y-2.5">
			{items.map((item, index) => (
				<div
					key={`${item.occurred_at}-${item.path}-${index}`}
					className="grid grid-cols-[3.25rem_1fr] gap-3 text-sm"
				>
					<div className="text-right text-xs tabular-nums text-zinc-500">
						{formatTime(item.occurred_at, item.occurred_at_human)}
					</div>
					<div className="border-l border-zinc-200 pl-3 dark:border-zinc-700">
						<p className="font-medium text-zinc-900 dark:text-zinc-50">
							{item.label || item.title || "Pagina visitada"}
						</p>
						{item.title && item.title !== item.label ? (
							<p className="mt-0.5 text-xs text-zinc-500">
								{item.title}
							</p>
						) : null}
						{item.path ? (
							<p className="mt-1 break-words text-xs text-zinc-400">
								{item.path}
							</p>
						) : null}
					</div>
				</div>
			))}
		</div>
	);
}

function LoadingState() {
	return (
		<div className="flex items-center gap-2 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
			<ArrowPathIcon className="size-4 animate-spin" />
			Cargando detalle...
		</div>
	);
}

function ErrorState({ onRetry }) {
	return (
		<div className="space-y-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
			<p>No fue posible cargar el detalle del carrito.</p>
			<Button outline onClick={onRetry}>
				Reintentar
			</Button>
		</div>
	);
}

export default function CartDetailDrawer({
	open,
	detail,
	loading = false,
	error = false,
	onClose,
	onRetry,
}) {
	const cart = detail?.cart;
	const customer = detail?.customer;
	const operationalInsight = detail?.operational_insight;
	const payment = detail?.payment;
	const finalPayment = detail?.final_payment;
	const paymentHistory = detail?.payment_history || [];
	const appointment = detail?.appointment;
	const appointmentJourney = detail?.appointment_journey || [];
	const contact = detail?.contact;
	const activeCampaign = detail?.activecampaign;
	const clientContext = detail?.client_context;
	const webActivity = detail?.web_activity;
	const events = detail?.events || [];
	const history = detail?.history;
	const links = detail?.links || {};
	const journey = detail?.journey || detail?.checkout?.journey || [];
	const appointmentNeedsAttention = Boolean(
		appointment &&
			(!finalPayment ||
				appointment.status_label?.includes("Pendiente") ||
				appointment.status_label?.includes("sin pago")),
	);
	const hasContactSignal = Boolean(
		contact?.callback_requested || contact?.phone_call_intent,
	);
	const sessionContextSummary = SessionContextSummary(clientContext);
	const webActivityCount = Number(
		webActivity?.count || webActivity?.items?.length || 0,
	);

	return (
		<Headless.Dialog
			open={open}
			onClose={onClose}
			className="relative z-50"
		>
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/30 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-[32rem] flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="sticky top-0 z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
						<div className="flex items-start justify-between gap-3 px-5 py-3.5">
							<div className="min-w-0">
								<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-famedic-light">
									Detalle 360 del carrito
								</p>
								<Headless.DialogTitle className="mt-1 truncate text-lg font-semibold text-zinc-900 dark:text-zinc-50">
									{customer?.name ||
										(loading ? "Cargando..." : "Cliente")}
								</Headless.DialogTitle>
								{customer?.email ? (
									<Text className="mt-0.5 truncate text-xs text-zinc-500">
										{customer.email}
									</Text>
								) : null}
								<div className="mt-2 flex flex-wrap gap-1.5">
									<MiniLink href={links.user_url}>
										Ver usuario
									</MiniLink>
									<MiniLink href={links.customer_url}>
										Ver cliente
									</MiniLink>
								</div>
							</div>
							<button
								type="button"
								onClick={onClose}
								className="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 focus:outline-none focus:ring-2 focus:ring-famedic-dark/30 dark:hover:bg-zinc-800 dark:focus:ring-famedic-light/40"
							>
								<XMarkIcon className="size-5" />
							</button>
						</div>
						<SummaryStrip
							cart={cart}
							finalPayment={finalPayment}
							operationalInsight={operationalInsight}
						/>
					</div>

					<div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
						{loading && !detail ? <LoadingState /> : null}
						{error ? <ErrorState onRetry={onRetry} /> : null}

						{detail ? (
							<>
								<Section
									title="Siguiente accion"
									icon={ArrowPathIcon}
								>
									<div className="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/60">
										<div className="flex flex-wrap items-center gap-2">
											<Badge
												color={toneColor(
													operationalInsight?.tone,
												)}
											>
												{operationalInsight?.label ||
													"Sin atencion requerida"}
											</Badge>
											<p className="text-sm text-zinc-800 dark:text-zinc-100">
												{operationalInsight?.recommended_action ||
													"Sin accion inmediata"}
											</p>
										</div>
									</div>
								</Section>

								<Section
									title="Resumen"
									icon={ShoppingCartIcon}
								>
									<div className="space-y-2.5">
										<div className="flex flex-wrap gap-1.5">
											{cart?.type_label ? (
												<Badge color="slate">
													{cart.type_label}
												</Badge>
											) : null}
											{cart?.brand_label ? (
												<Badge color="zinc">
													{cart.brand_label}
												</Badge>
											) : null}
											{customer?.segment_label ? (
												<Badge color="famedic-lime">
													{customer.segment_label}
												</Badge>
											) : null}
											{cart?.checkout_flow?.label ? (
												<Badge
													color={
														cart.checkout_flow
															.confidence ===
														"unknown"
															? "zinc"
															: "sky"
													}
												>
													{cart.checkout_flow.label}
												</Badge>
											) : null}
										</div>
										<div className="grid grid-cols-2 gap-x-3 gap-y-2">
											<Field
												label="Carrito"
												value={cart?.items_label}
											/>
											<Field
												label="Monto"
												value={cart?.total_formatted}
											/>
											<Field
												label="Estado"
												value={
													cart?.display_status_label
												}
											/>
											<Field
												label="Ultima actividad"
												value={
													cart?.last_user_activity_human ||
													cart?.updated_at_human
												}
											/>
										</div>
									</div>
								</Section>

								<Section
									title="Journey general"
									icon={BeakerIcon}
								>
									<Journey steps={journey} />
								</Section>

								{clientContext?.has_data ? (
									<DisclosureSection
										title="Contexto de sesion"
										summary={sessionContextSummary}
									>
										<SessionContext
											context={clientContext}
										/>
									</DisclosureSection>
								) : null}

								{webActivity?.has_data ? (
									<DisclosureSection
										title="Actividad web"
										icon={ArrowPathIcon}
										summary={`${webActivityCount} pagina${webActivityCount === 1 ? "" : "s"}`}
									>
										<WebActivityTimeline
											items={webActivity.items || []}
										/>
									</DisclosureSection>
								) : null}

								{finalPayment ? (
									<Section
										title="Pago final"
										icon={CreditCardIcon}
									>
										<div className="rounded-md border border-green-200 bg-green-50 px-3 py-3 dark:border-green-900 dark:bg-green-950/30">
											<div className="flex items-start justify-between gap-3">
												<div>
													<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
														{
															finalPayment.method_label
														}
													</p>
													<p className="mt-1 text-lg font-semibold tabular-nums text-green-800 dark:text-green-200">
														{finalPayment.amount}
													</p>
												</div>
												<Badge color="green">
													{finalPayment.status_label ||
														"Aprobado"}
												</Badge>
											</div>
											<p className="mt-2 text-xs text-zinc-500">
												{formatDateTime(
													finalPayment.paid_at,
													finalPayment.paid_at_human,
												)}
											</p>
										</div>
									</Section>
								) : null}

								{paymentHistory.length ? (
									<DisclosureSection
										title="Historial de pagos"
										icon={CreditCardIcon}
										summary={paymentHistorySummary(
											paymentHistory,
										)}
									>
										<PaymentTimeline
											items={paymentHistory}
										/>
									</DisclosureSection>
								) : null}

								{appointmentJourney.length || appointment ? (
									<DisclosureSection
										title="Cita"
										icon={CalendarDaysIcon}
										summary={
											appointment?.status_label ||
											"Sin cita"
										}
										defaultOpen={appointmentNeedsAttention}
									>
										<div className="space-y-3">
											<AppointmentJourney
												steps={appointmentJourney}
												appointment={appointment}
											/>
											{appointment ? (
												<div className="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
													<Field
														label="Laboratorio"
														value={
															appointment.brand_label
														}
													/>
													<Field
														label="Sucursal"
														value={
															appointment.store_name
														}
													/>
													<Field
														label="Fecha/hora"
														value={
															appointment.appointment_date_human
														}
													/>
													<Field
														label="Direccion"
														value={
															appointment.store_address
														}
													/>
												</div>
											) : null}
										</div>
									</DisclosureSection>
								) : null}

								{hasContactSignal ? (
									<DisclosureSection
										title="Contacto"
										icon={PhoneIcon}
										summary={
											contact?.callback_requested
												? "Solicito llamada"
												: "Intento llamar"
										}
										defaultOpen
									>
										<div className="grid gap-2">
											{contact.callback_requested ? (
												<>
													<Field
														label="Disponibilidad"
														value={
															contact
																.callback_requested
																.availability_label ||
															"Sin horario especificado"
														}
													/>
													<Field
														label="Comentario"
														value={
															contact
																.callback_requested
																.comment
														}
													/>
												</>
											) : null}
											{contact.phone_call_intent ? (
												<Field
													label="Intento llamar"
													value={
														contact
															.phone_call_intent
															.at_human
													}
												/>
											) : null}
										</div>
									</DisclosureSection>
								) : null}

								{activeCampaign?.has_data ? (
									<DisclosureSection
										title="ActiveCampaign"
										icon={ArrowPathIcon}
										summary={ActiveCampaignSummary(
											activeCampaign,
										)}
									>
										<SimpleTimeline
											items={activeCampaign.items || []}
											empty="Sin actividad local asociada"
										/>
									</DisclosureSection>
								) : null}

								{events.length ? (
									<DisclosureSection
										title="Actividad del carrito"
										icon={ShoppingCartIcon}
										summary={`${events.length} evento${events.length === 1 ? "" : "s"}`}
									>
										<SimpleTimeline
											items={events}
											empty="Sin eventos instrumentados"
											showSeconds
										/>
									</DisclosureSection>
								) : null}

								<DisclosureSection
									title="Cliente"
									icon={UserIcon}
									summary={CustomerSummary({
										customer,
										history,
									})}
								>
									<div className="grid grid-cols-2 gap-x-3 gap-y-2">
										<Field
											label="Clasificacion"
											value={customer?.segment_label}
										/>
										<Field
											label="Compras previas"
											value={
												customer?.previous_purchases_label
											}
										/>
										<Field
											label="Registro"
											value={history?.registered_at_human}
										/>
										<Field
											label="Ultima compra"
											value={history?.last_purchase_label}
										/>
										<Field
											label="Valor historico"
											value={
												history?.historical_value_formatted
											}
										/>
									</div>
								</DisclosureSection>
							</>
						) : null}
					</div>

					<div className="sticky bottom-0 flex flex-col gap-2 border-t border-zinc-200 bg-white px-5 py-3 sm:flex-row dark:border-zinc-700 dark:bg-zinc-900">
						{links.purchase_url ? (
							<Button
								href={links.purchase_url}
								outline
								className="flex-1"
							>
								Ver compra
							</Button>
						) : null}
						{links.appointment_url ? (
							<Button
								href={links.appointment_url}
								outline
								className="flex-1"
							>
								Ver cita
							</Button>
						) : null}
						<Button outline className="flex-1" onClick={onClose}>
							Cerrar
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
