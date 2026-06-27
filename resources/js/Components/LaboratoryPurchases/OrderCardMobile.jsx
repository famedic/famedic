import clsx from "clsx";
import { LockClosedIcon } from "@heroicons/react/24/solid";
import { Text, Strong } from "@/Components/Catalyst/text";
import NewResultBadge from "@/Components/Laboratory/NewResultBadge";
import OrderRowActions from "@/Components/LaboratoryPurchases/OrderRowActions";
import { getOrderBadgePresentation, purchaseHasResults, studiesExtraCount } from "@/lib/laboratoryPurchaseOrderUi";
import PaymentMethodDisplayIcon from "@/Components/PaymentMethodDisplayIcon";
import OrderFolioBadges from "@/Components/LaboratoryPurchases/OrderFolioBadges";
import { GiftIcon } from "@heroicons/react/16/solid";

export default function OrderCardMobile({ purchase, beginProtectedUrl }) {
	const badge = getOrderBadgePresentation(purchase);
	const extraStudies = studiesExtraCount(purchase);
	const hasProtectedResults = purchaseHasResults(purchase);

	return (
		<article
			className={clsx(
				"rounded-2xl border border-zinc-200/90 bg-white/80 p-4 shadow-sm dark:border-slate-700/90 dark:bg-slate-900/70",
				"transition duration-150 dark:shadow-none",
			)}
		>
			<div className="flex items-start justify-between gap-3">
				<div className="min-w-0 flex-1">
					<div className="flex items-start gap-2">
						<Text className="min-w-0 flex-1 text-base font-semibold leading-snug text-zinc-900 dark:text-white">
							{purchase.study_name}
						</Text>
						{extraStudies > 0 && (
							<span
								className="shrink-0 rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200/80 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600"
								title={`${(purchase.studies_count ?? purchase.items_count) || 0} estudios en el pedido`}
							>
								+{extraStudies}
							</span>
						)}
					</div>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-slate-400">
						<Strong className="font-medium text-zinc-800 dark:text-slate-200">{purchase.patient_name}</Strong>
					</Text>
					{purchase.is_new_result && (
						<div className="mt-2">
							<NewResultBadge compact />
						</div>
					)}
					{hasProtectedResults && (
						<span
							className="mt-2 inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-slate-800 dark:text-slate-200"
							title="Tus resultados están protegidos. Te pediremos un código OTP."
						>
							<LockClosedIcon className="size-3" />
							🔒 Protegido
						</span>
					)}
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

			{purchase.cancelled_at_formatted && (
				<Text className="mt-2 text-xs text-red-600 dark:text-red-300">
					Cancelado el {purchase.cancelled_at_formatted}
				</Text>
			)}

			<dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm text-zinc-600 dark:text-slate-400">
				<div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-zinc-100 pt-3 dark:border-slate-800">
					<dt className="sr-only">Folio y consecutivo</dt>
					<dd>
						<OrderFolioBadges purchase={purchase} emptyLabel="Sin folio" />
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
						<span className="inline-flex flex-wrap items-center justify-end gap-1.5">
							<PaymentMethodDisplayIcon
								method={purchase.payment_method}
								label={purchase.payment_method_label}
								size="sm"
							/>
							{purchase.show_credit_gift_next_to_payment && (
								<span
									className="inline-flex shrink-0"
									title="Se usó crédito a favor"
									role="img"
									aria-label="Se usó crédito a favor"
								>
									<GiftIcon className="size-4 shrink-0 fill-orange-500 dark:fill-orange-400" aria-hidden />
								</span>
							)}
						</span>
					</dd>
				</div>
			</dl>

			<div className="mt-4 border-t border-zinc-100 pt-4 dark:border-slate-800">
				<OrderRowActions purchase={purchase} beginProtectedUrl={beginProtectedUrl} layout="mobile" />
			</div>
		</article>
	);
}
