import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Code } from "@/Components/Catalyst/text";
import CreditCardBrand from "@/Components/CreditCardBrand";

export default function OrderSummary({ totals }) {
	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<h2 className="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">
				Resumen de orden
			</h2>
			<div className="min-w-0 space-y-3 text-sm">
				<Row label="Subtotal" value={totals.subtotal} />
				<Row label="Descuentos" value={totals.discount} />
				<Row label="Total" value={totals.total} strong />
				<Row label="Método de pago" value={totals.paymentMethod} />
				{totals.paymentMethodKey === "stripe" &&
					totals.cardBrand &&
					totals.cardLastFour && (
						<div className="flex min-w-0 flex-col gap-2 rounded-lg border border-zinc-100 bg-zinc-50/80 px-3 py-2 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700 dark:bg-slate-800/50">
							<span className="shrink-0 text-zinc-500 dark:text-slate-400">Tarjeta</span>
							<div className="flex min-w-0 flex-wrap items-center gap-2">
								<CreditCardBrand brand={totals.cardBrand} />
								<Code className="text-sm">**** {totals.cardLastFour}</Code>
							</div>
						</div>
					)}
				{totals.paymentMethodKey === "odessa" && (
					<div className="flex min-w-0 flex-wrap items-center gap-3 rounded-lg border border-zinc-100 bg-zinc-50/80 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/50">
						<img src="/images/odessa.png" alt="" className="size-8 shrink-0" />
						<div className="min-w-0">
							<p className="font-medium text-zinc-800 dark:text-slate-100">Odessa</p>
							<p className="text-xs text-orange-600 dark:text-orange-400">Cobro a caja de ahorro</p>
						</div>
					</div>
				)}
				{totals.paymentMethodKey === "efevoopay" && (
					<p className="break-words text-xs text-zinc-500 dark:text-slate-400">
						Pago procesado en línea (Efevoo Pay). Si necesitas comprobante adicional, escríbenos por WhatsApp al
						concierge (+52 (554) 057 2139).
					</p>
				)}
				<div className="flex min-w-0 flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-slate-700">
					<span className="text-zinc-500 dark:text-slate-400">Estado de pago</span>
					<Badge color={totals.paymentStatusColor} className="shrink-0">
						{totals.paymentStatusLabel}
					</Badge>
				</div>
			</div>
		</Card>
	);
}

function Row({ label, value, strong = false }) {
	return (
		<div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
			<span className="shrink-0 text-zinc-500 dark:text-slate-400">{label}</span>
			<span
				className={`min-w-0 max-w-full text-right break-words ${
					strong ? "font-semibold text-zinc-900 dark:text-white" : "text-zinc-800 dark:text-slate-200"
				}`}
			>
				{value}
			</span>
		</div>
	);
}
