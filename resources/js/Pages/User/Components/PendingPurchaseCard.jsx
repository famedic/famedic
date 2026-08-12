import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import CheckoutProgress from "@/Pages/User/Components/CheckoutProgress";
import {
	CalendarDaysIcon,
	ClockIcon,
	CurrencyDollarIcon,
	ShoppingCartIcon,
} from "@heroicons/react/24/outline";
import clsx from "clsx";
import { useState } from "react";

const statusConfig = {
	cart_saved: {
		label: "Carrito guardado",
		color: "sky",
		action: "Continuar compra",
		actionName: "continue_cart",
		icon: ShoppingCartIcon,
	},
	checkout_in_progress: {
		label: "Checkout en progreso",
		color: "famedic-lime",
		action: "Continuar checkout",
		actionName: "continue_checkout",
		icon: ClockIcon,
	},
	appointment_pending: {
		label: "Cita pendiente",
		color: "amber",
		action: "Continuar con mi cita",
		actionName: "continue_appointment",
		icon: CalendarDaysIcon,
	},
	payment_pending: {
		label: "Pendiente de pago",
		color: "emerald",
		action: "Continuar al pago",
		actionName: "continue_payment",
		icon: CurrencyDollarIcon,
	},
};

const checkoutProgressStatuses = [
	"checkout_in_progress",
	"appointment_pending",
	"payment_pending",
];

export default function PendingPurchaseCard({ purchase }) {
	const [expanded, setExpanded] = useState(false);

	if (!purchase) {
		return null;
	}

	const config = statusConfig[purchase.status] ?? statusConfig.cart_saved;
	const StatusIcon = config.icon;
	const brandLabel = purchase.brand?.label || "Laboratorio";
	const items = Array.isArray(purchase.items) ? purchase.items : [];
	const visibleItems = expanded ? items : items.slice(0, 3);
	const hiddenItems = Math.max(0, items.length - visibleItems.length);
	const pricing = purchase.pricing ?? {};
	const activityLabel = formatActivity(purchase.activity?.last_activity_at);
	const continueUrl = purchase.urls?.continue;
	const showCheckoutProgress = checkoutProgressStatuses.includes(
		purchase.status,
	);

	return (
		<Card className="overflow-hidden">
			<div className="grid gap-0 lg:grid-cols-[minmax(0,1fr)_18rem]">
				<div className="space-y-6 p-5 sm:p-6">
					<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
						<div className="min-w-0">
							<p className="text-sm font-medium text-zinc-500 dark:text-slate-400">
								Laboratorio
							</p>
							<Subheading className="mt-1 truncate text-xl">
								{brandLabel}
							</Subheading>
						</div>

						<div className="flex flex-wrap gap-2">
							<Badge color={config.color}>
								<StatusIcon
									className="size-4"
									aria-hidden="true"
								/>
								{purchase.status_label || config.label}
							</Badge>
							{purchase.requires_appointment ? (
								<Badge
									color={
										purchase.status ===
										"appointment_pending"
											? "amber"
											: "slate"
									}
								>
									<CalendarDaysIcon
										className="size-4"
										aria-hidden="true"
									/>
									Requiere cita
								</Badge>
							) : null}
						</div>
					</div>

					<div className="space-y-3">
						<div className="flex items-center justify-between gap-3">
							<p className="font-poppins text-sm font-semibold text-famedic-darker dark:text-white">
								{formatItemsCount(purchase.items_count)}
							</p>
							{activityLabel ? (
								<p className="text-xs text-zinc-500 dark:text-slate-400">
									Última actividad: {activityLabel}
								</p>
							) : null}
						</div>

						{visibleItems.length > 0 ? (
							<ul className="divide-y divide-zinc-100 rounded-lg border border-zinc-100 dark:divide-slate-800 dark:border-slate-800">
								{visibleItems.map((item, index) => (
									<li
										key={`${item.name ?? "estudio"}-${index}`}
										className="flex items-start justify-between gap-4 px-3 py-2.5"
									>
										<span className="min-w-0 text-sm font-medium text-zinc-700 dark:text-slate-200">
											{item.name ||
												"Estudio de laboratorio"}
										</span>
										{item.requires_appointment ? (
											<CalendarDaysIcon
												className="mt-0.5 size-4 shrink-0 text-famedic-light"
												aria-label="Requiere cita"
											/>
										) : null}
									</li>
								))}
							</ul>
						) : (
							<Text>No encontramos estudios para mostrar.</Text>
						)}

						{items.length > 3 ? (
							<button
								type="button"
								onClick={() => setExpanded((value) => !value)}
								className="text-sm font-semibold text-famedic-dark underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-famedic-light focus:ring-offset-2 dark:text-famedic-lime dark:focus:ring-offset-slate-900"
							>
								{expanded
									? "Ver menos"
									: `+ ${hiddenItems} estudios más`}
							</button>
						) : null}
					</div>

					{showCheckoutProgress ? (
						<CheckoutProgress checkout={purchase.checkout} />
					) : (
						<p className="text-sm font-medium text-zinc-500 dark:text-slate-400">
							Listo para continuar
						</p>
					)}
				</div>

				<aside className="flex flex-col justify-between gap-6 border-t border-zinc-100 bg-zinc-50/70 p-5 sm:p-6 lg:border-l lg:border-t-0 dark:border-slate-800 dark:bg-slate-900/45">
					<div className="space-y-3">
						<PriceLine
							label="Antes"
							value={formatMoney(
								pricing.subtotal,
								pricing.formatted_subtotal,
							)}
							muted
						/>
						{Number(pricing.discount ?? 0) > 0 ? (
							<PriceLine
								label="Ahorras"
								value={formatMoney(
									pricing.discount,
									pricing.formatted_discount,
								)}
								positive
							/>
						) : null}
						<div className="border-t border-zinc-200 pt-3 dark:border-slate-800">
							<p className="text-sm font-medium text-zinc-500 dark:text-slate-400">
								Total
							</p>
							<p className="mt-1 font-poppins text-3xl font-semibold text-famedic-darker dark:text-white">
								{formatMoney(
									pricing.total,
									pricing.formatted_total,
								)}
							</p>
						</div>
					</div>

					<div className="space-y-3">
						{continueUrl ? (
							<Button
								href={continueUrl}
								color="famedic"
								className="w-full"
								data-pending-action={config.actionName}
							>
								{config.action}
							</Button>
						) : null}

						{purchase.urls?.cart &&
						purchase.status !== "cart_saved" ? (
							<Button
								href={purchase.urls.cart}
								outline
								className="w-full"
								data-pending-action="view_cart"
							>
								Ver carrito
							</Button>
						) : null}
					</div>
				</aside>
			</div>
		</Card>
	);
}

function PriceLine({ label, value, muted, positive }) {
	if (!value) return null;

	return (
		<div className="flex items-center justify-between gap-4 text-sm">
			<span className="text-zinc-500 dark:text-slate-400">{label}</span>
			<span
				className={clsx(
					"font-medium",
					muted && "text-zinc-500 line-through dark:text-slate-500",
					positive && "text-emerald-700 dark:text-emerald-300",
					!muted &&
						!positive &&
						"text-famedic-darker dark:text-white",
				)}
			>
				{value}
			</span>
		</div>
	);
}

function formatItemsCount(value) {
	const count = Number(value ?? 0);
	return `${count} ${count === 1 ? "estudio" : "estudios"}`;
}

function formatMoney(value, formattedValue) {
	if (formattedValue) return formattedValue;

	const cents = Number(value ?? 0);
	if (!Number.isFinite(cents)) return null;

	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(cents / 100);
}

function formatActivity(value) {
	if (!value) return null;

	const date = new Date(value);
	if (Number.isNaN(date.getTime())) return null;

	return new Intl.DateTimeFormat("es-MX", {
		day: "numeric",
		month: "short",
		hour: "2-digit",
		minute: "2-digit",
	}).format(date);
}
