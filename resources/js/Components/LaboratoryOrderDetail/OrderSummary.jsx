import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Code } from "@/Components/Catalyst/text";
import CreditCardBrand from "@/Components/CreditCardBrand";
import PaymentMethodDisplayIcon from "@/Components/PaymentMethodDisplayIcon";
import { GiftIcon } from "@heroicons/react/16/solid";

export default function OrderSummary({ totals }) {
	const showCreditInDiscounts = Boolean(totals.hasAppliedCreditBalance);

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<h2 className="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">
				Resumen de orden
			</h2>
			<div className="min-w-0 space-y-3 text-sm">
				<Row label="Subtotal" value={totals.subtotal} />
				<DiscountRow amount={totals.discount} showCredit={showCreditInDiscounts} />
				<Row label="Total" value={totals.total} strong />
				<div className="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-2">
					<span className="shrink-0 text-zinc-500 dark:text-slate-400">Método de pago</span>
					<div className="flex min-w-0 max-w-full flex-wrap items-center justify-end gap-2 text-right">
						{totals.paymentMethodKey ? (
							<PaymentMethodDisplayIcon
								method={totals.paymentMethodKey}
								label={totals.paymentMethodLabel}
								size="sm"
								className="shrink-0"
							/>
						) : null}
						<span className="min-w-0 break-words font-medium text-zinc-800 dark:text-slate-200">
							{totals.paymentMethodLabel}
						</span>
					</div>
				</div>
				{(totals.paymentMethodKey === "stripe" || totals.paymentMethodKey === "efevoopay") &&
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
						Pago con tarjeta procesado por Efevoo Pay. Si necesitas comprobante adicional, escríbenos por WhatsApp al
						concierge (+52 (554) 057 2139).
					</p>
				)}
				{totals.paymentMethodKey === "paypal" && (
					<p className="break-words text-xs text-zinc-500 dark:text-slate-400">
						Pago realizado con PayPal.
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

function DiscountRow({ amount, showCredit }) {
	return (
		<div className="flex min-w-0 flex-wrap items-start justify-between gap-x-3 gap-y-2">
			<span className="shrink-0 text-zinc-500 dark:text-slate-400">Descuentos</span>
			<div className="min-w-0 max-w-full text-right">
				{showCredit ? (
					<div className="inline-flex max-w-full flex-wrap items-center justify-end gap-2 text-zinc-800 dark:text-slate-200">
						<span className="inline-flex shrink-0" title="Crédito a favor">
							<GiftIcon className="size-4 shrink-0 fill-orange-500 dark:fill-orange-400" aria-hidden />
						</span>
						<span className="text-sm font-medium text-orange-700 dark:text-orange-300">Crédito a favor</span>
						<span className="tabular-nums">−{amount}</span>
					</div>
				) : (
					<span className="tabular-nums text-zinc-800 dark:text-slate-200">{amount}</span>
				)}
			</div>
		</div>
	);
}
