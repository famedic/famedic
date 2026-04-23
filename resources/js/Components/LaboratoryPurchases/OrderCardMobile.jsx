import clsx from "clsx";
import { Text, Strong } from "@/Components/Catalyst/text";
import OrderRowActions from "@/Components/LaboratoryPurchases/OrderRowActions";
import { getOrderBadgePresentation } from "@/lib/laboratoryPurchaseOrderUi";
import PaymentMethodDisplayIcon from "@/Components/PaymentMethodDisplayIcon";

export default function OrderCardMobile({ purchase, requireOtpThen }) {
	const badge = getOrderBadgePresentation(purchase);
	const showFolio = !purchase.temporarly_hide_gda_order_id && Boolean(purchase.gda_order_id);

	return (
		<article
			className={clsx(
				"rounded-2xl border border-zinc-200/90 bg-white/80 p-4 shadow-sm dark:border-slate-700/90 dark:bg-slate-900/70",
				"transition duration-150 dark:shadow-none",
			)}
		>
			<div className="flex items-start justify-between gap-3">
				<div className="min-w-0 flex-1">
					<Text className="text-base font-semibold leading-snug text-zinc-900 dark:text-white">{purchase.study_name}</Text>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-slate-400">
						<Strong className="font-medium text-zinc-800 dark:text-slate-200">{purchase.patient_name}</Strong>
					</Text>
				</div>
				<span
					className={clsx(
						"shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold",
						badge.className,
					)}
				>
					{badge.label}
				</span>
			</div>

			<dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm text-zinc-600 dark:text-slate-400">
				<div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-zinc-100 pt-3 dark:border-slate-800">
					<dt className="sr-only">Folio</dt>
					<dd>
						{showFolio ? (
							<span>
								<span className="text-xs uppercase tracking-wide text-zinc-400 dark:text-slate-500">Folio </span>
								<span className="font-mono font-semibold text-zinc-900 dark:text-white">{purchase.gda_order_id}</span>
							</span>
						) : (
							<span className="text-zinc-400 dark:text-slate-500">Sin folio</span>
						)}
					</dd>
					<span className="hidden text-zinc-300 sm:inline dark:text-slate-600" aria-hidden>
						·
					</span>
					<dt className="sr-only">Laboratorio</dt>
					<dd className="min-w-0 truncate text-zinc-700 dark:text-slate-300">{purchase.laboratory_name}</dd>
				</div>
				<div>
					<dt className="text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-slate-500">Pedido</dt>
					<dd className="mt-0.5 text-zinc-800 dark:text-slate-200">{purchase.purchased_at_formatted}</dd>
				</div>
				<div className="text-right">
					<dt className="text-xs font-medium uppercase tracking-wide text-zinc-400 dark:text-slate-500">Total</dt>
					<dd className="mt-0.5 font-semibold text-zinc-900 dark:text-white">{purchase.formatted_total}</dd>
					<dd className="mt-1 flex justify-end">
						<PaymentMethodDisplayIcon
							method={purchase.payment_method}
							label={purchase.payment_method_label}
							size="sm"
						/>
					</dd>
				</div>
			</dl>

			<div className="mt-4 border-t border-zinc-100 pt-4 dark:border-slate-800">
				<OrderRowActions purchase={purchase} requireOtpThen={requireOtpThen} layout="mobile" />
			</div>
		</article>
	);
}
