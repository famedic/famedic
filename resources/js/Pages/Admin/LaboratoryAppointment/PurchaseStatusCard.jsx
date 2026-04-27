import {
	CheckCircleIcon,
	ShoppingCartIcon,
	ClipboardDocumentCheckIcon,
	CalendarDaysIcon,
} from "@heroicons/react/24/outline";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";

export default function PurchaseStatusCard({
	purchaseStatus,
	orderNumber,
	purchaseDate,
	studies = [],
	paymentUrl = null,
}) {
	const isPaid = purchaseStatus === "paid";
	const completed = studies.filter((study) => study.status === "completed").length;
	const pending = studies.filter((study) => study.status === "pending").length;
	const total = studies.length;

	return (
		<div className="space-y-4 rounded-2xl border border-sky-300/30 bg-sky-500/10 p-5 shadow-sm dark:border-sky-700/40 dark:bg-sky-900/20">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="space-y-2">
					<div className="flex items-center gap-3">
						{isPaid ? (
							<CheckCircleIcon className="size-6 text-emerald-400" />
						) : (
							<ShoppingCartIcon className="size-6 text-amber-400" />
						)}
						<h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
							{isPaid ? "Compra concretada" : "Pendiente de pago"}
						</h2>
						<Badge color={isPaid ? "emerald" : "amber"}>
							{isPaid ? "Pagada" : "Carrito"}
						</Badge>
					</div>
					<p className="text-sm text-zinc-600 dark:text-zinc-300">
						{isPaid
							? "La orden fue pagada y confirmada."
							: "Los estudios aun no han sido pagados."}
					</p>
				</div>
				{!isPaid && paymentUrl && (
					<Button href={paymentUrl} color="sky">
						Ir a pagar
					</Button>
				)}
			</div>

			{isPaid && (
				<div className="grid gap-3 sm:grid-cols-2">
					<div className="rounded-xl border border-zinc-200/70 bg-white/70 p-3 dark:border-zinc-800 dark:bg-zinc-900/60">
						<div className="mb-1 inline-flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
							<ClipboardDocumentCheckIcon className="size-4" />
							Numero de orden
						</div>
						<div className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
							{orderNumber ?? "No disponible"}
						</div>
					</div>
					<div className="rounded-xl border border-zinc-200/70 bg-white/70 p-3 dark:border-zinc-800 dark:bg-zinc-900/60">
						<div className="mb-1 inline-flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
							<CalendarDaysIcon className="size-4" />
							Fecha de compra
						</div>
						<div className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
							{purchaseDate ?? "No disponible"}
						</div>
					</div>
				</div>
			)}

			{total > 1 && (
				<div className="grid gap-3 sm:grid-cols-3">
					<Metric label="Total de estudios" value={total} />
					<Metric label="Completados" value={completed} tone="emerald" />
					<Metric label="Pendientes" value={pending} tone="amber" />
				</div>
			)}
		</div>
	);
}

function Metric({ label, value, tone = "slate" }) {
	const toneClass = {
		slate: "text-zinc-900 dark:text-zinc-100",
		emerald: "text-emerald-500",
		amber: "text-amber-400",
	}[tone];

	return (
		<div className="rounded-xl border border-zinc-200/70 bg-white/70 p-3 dark:border-zinc-800 dark:bg-zinc-900/60">
			<p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
			<p className={`text-lg font-semibold ${toneClass}`}>{value}</p>
		</div>
	);
}
