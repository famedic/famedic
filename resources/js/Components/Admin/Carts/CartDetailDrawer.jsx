import * as Headless from "@headlessui/react";
import {
	ArrowPathIcon,
	BeakerIcon,
	CalendarDaysIcon,
	CreditCardIcon,
	PhoneIcon,
	ShoppingCartIcon,
	UserIcon,
	XMarkIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

function Section({ title, icon: Icon, children }) {
	return (
		<section className="space-y-3 border-b border-zinc-200 pb-5 last:border-0 dark:border-zinc-700">
			<div className="flex items-center gap-2">
				{Icon ? <Icon className="size-4 text-zinc-400" /> : null}
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
					{title}
				</h3>
			</div>
			{children}
		</section>
	);
}

function Field({ label, value }) {
	return (
		<div>
			<p className="text-[11px] uppercase tracking-wide text-zinc-400">
				{label}
			</p>
			<p className="mt-0.5 text-sm text-zinc-800 dark:text-zinc-100">
				{value || "—"}
			</p>
		</div>
	);
}

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

function journeyColor(state) {
	return (
		{
			completed:
				"border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200",
			current:
				"border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200",
			failed:
				"border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200",
			pending:
				"border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300",
		}[state] || "border-zinc-200 bg-zinc-50 text-zinc-600"
	);
}

function journeyMark(state) {
	return (
		{
			completed: "✓",
			current: "•",
			failed: "×",
			pending: "○",
		}[state] || "○"
	);
}

function Journey({ steps = [] }) {
	return (
		<div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
			{steps.map((step) => (
				<div
					key={step.key}
					className={`rounded-lg border px-3 py-2 ${journeyColor(step.state)}`}
				>
					<div className="flex items-center justify-between gap-2">
						<p className="text-sm font-medium">{step.label}</p>
						<span className="text-sm font-semibold">{journeyMark(step.state)}</span>
					</div>
					<p className="mt-1 text-xs opacity-80">{step.detail}</p>
				</div>
			))}
		</div>
	);
}

function LoadingState() {
	return (
		<div className="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
			<ArrowPathIcon className="size-4 animate-spin" />
			Cargando detalle...
		</div>
	);
}

function ErrorState({ onRetry }) {
	return (
		<div className="space-y-3 rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
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
	const appointment = detail?.appointment;
	const contact = detail?.contact;
	const history = detail?.history;
	const links = detail?.links || {};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/30 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-[31rem] flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-zinc-200 bg-white px-5 py-4 dark:border-zinc-700 dark:bg-zinc-900">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-famedic-light">
								Detalle 360 del carrito
							</p>
							<Headless.DialogTitle className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
								{customer?.name || (loading ? "Cargando..." : "Cliente")}
							</Headless.DialogTitle>
							{customer?.email ? (
								<Text className="mt-0.5 text-xs text-zinc-500">
									{customer.email}
								</Text>
							) : null}
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 focus:outline-none focus:ring-2 focus:ring-famedic-dark/30 dark:hover:bg-zinc-800 dark:focus:ring-famedic-light/40"
						>
							<XMarkIcon className="size-5" />
						</button>
					</div>

					<div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{loading && !detail ? <LoadingState /> : null}
						{error ? <ErrorState onRetry={onRetry} /> : null}

						{detail ? (
							<>
								{operationalInsight ? (
									<Section title="Siguiente acción" icon={ArrowPathIcon}>
										<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/60">
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Razón de atención
											</p>
											<div className="mt-1">
												<Badge color={toneColor(operationalInsight.tone)}>
													{operationalInsight.label}
												</Badge>
											</div>
											<p className="mt-3 text-[11px] uppercase tracking-wide text-zinc-400">
												Acción sugerida
											</p>
											<p className="mt-1 text-sm text-zinc-800 dark:text-zinc-100">
												{operationalInsight.recommended_action}
											</p>
										</div>
									</Section>
								) : null}

								<Section title="Resumen" icon={ShoppingCartIcon}>
									<div className="space-y-3">
										<div className="flex flex-wrap gap-2">
											<Badge color="slate">{cart?.type_label}</Badge>
											{cart?.brand_label ? (
												<Badge color="zinc">{cart.brand_label}</Badge>
											) : null}
											<Badge color="famedic-lime">{customer?.segment_label}</Badge>
										</div>
										<div className="grid grid-cols-2 gap-3">
											<Field label="Carrito" value={cart?.items_label} />
											<Field label="Monto" value={cart?.total_formatted} />
											<Field label="Estado" value={cart?.status_summary} />
											<Field
												label="Última actividad"
												value={cart?.updated_at_human}
											/>
										</div>
									</div>
								</Section>

								<Section title="Journey" icon={BeakerIcon}>
									<Journey steps={detail.checkout?.journey || []} />
								</Section>

								{payment ? (
									<Section title="Pago" icon={CreditCardIcon}>
										{payment.confidence === "ambiguous" ? (
											<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/60">
												<Badge color="zinc">{payment.status_label}</Badge>
												<p className="mt-2 text-sm text-zinc-500">
													{payment.note}
												</p>
											</div>
										) : (
											<div className="grid grid-cols-2 gap-3">
												<Field label="Gateway" value={payment.gateway_label} />
												<div>
													<p className="text-[11px] uppercase tracking-wide text-zinc-400">
														Estado
													</p>
													<div className="mt-1">
														<Badge color={toneColor(payment.status_tone)}>
															{payment.status_label}
														</Badge>
													</div>
												</div>
												<Field
													label="Intentos"
													value={String(payment.attempts_count || 0)}
												/>
												<Field
													label="Último intento"
													value={
														payment.last_attempt?.occurred_for_label ||
														payment.last_attempt?.occurred_at_human
													}
												/>
												<Field
													label="Código"
													value={payment.last_attempt?.processor_code}
												/>
												<Field
													label="Mensaje"
													value={payment.last_attempt?.processor_message}
												/>
											</div>
										)}
									</Section>
								) : null}

								{appointment ? (
									<Section title="Cita" icon={CalendarDaysIcon}>
										<div className="grid grid-cols-2 gap-3">
											<Field label="Laboratorio" value={appointment.brand_label} />
											<Field label="Sucursal" value={appointment.store_name} />
											<Field label="Fecha" value={appointment.appointment_date_human} />
											<Field label="Estado" value={appointment.status_label} />
											<Field
												label="Espera"
												value={appointment.waiting_label}
											/>
											<Field
												label="Dirección sucursal"
												value={appointment.store_address}
											/>
										</div>
									</Section>
								) : null}

								{contact ? (
									<Section title="Contacto" icon={PhoneIcon}>
										<div className="grid gap-3">
											{contact.phone_call_intent ? (
												<Field
													label={contact.phone_call_intent.label}
													value={contact.phone_call_intent.at_human}
												/>
											) : null}
											{contact.callback_requested ? (
												<>
													<Field
														label={contact.callback_requested.label}
														value={
															contact.callback_requested.availability_label ||
															"Sin horario especificado"
														}
													/>
													<Field
														label="Comentario"
														value={contact.callback_requested.comment}
													/>
												</>
											) : null}
										</div>
									</Section>
								) : null}

								<Section title="Cliente" icon={UserIcon}>
									<div className="grid grid-cols-2 gap-3">
										<Field label="Clasificación" value={customer?.segment_label} />
										<Field
											label="Compras anteriores"
											value={customer?.previous_purchases_label}
										/>
										<Field
											label="Fecha de registro"
											value={history?.registered_at_human}
										/>
										<Field
											label="Última compra"
											value={history?.last_purchase_label}
										/>
										<Field
											label="Valor histórico"
											value={history?.historical_value_formatted}
										/>
										<Field label="Correo" value={customer?.email} />
									</div>
								</Section>
							</>
						) : null}
					</div>

					<div className="flex flex-col gap-2 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700 sm:flex-row">
						{links.purchase_url ? (
							<Button href={links.purchase_url} outline className="flex-1">
								Ver compra
							</Button>
						) : null}
						{links.appointment_url ? (
							<Button href={links.appointment_url} outline className="flex-1">
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
