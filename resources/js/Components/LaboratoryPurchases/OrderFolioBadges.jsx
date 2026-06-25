import { QrCodeIcon } from "@heroicons/react/24/solid";

export function resolveShowFolio(purchase) {
	return !purchase.temporarly_hide_gda_order_id && Boolean(purchase.gda_order_id);
}

export function resolveGdaConsecutivo(purchase) {
	return purchase.gda_consecutivo != null && purchase.gda_consecutivo !== ""
		? String(purchase.gda_consecutivo)
		: null;
}

export default function OrderFolioBadges({ purchase, emptyLabel = "—" }) {
	const showFolio = resolveShowFolio(purchase);
	const gdaConsecutivo = resolveGdaConsecutivo(purchase);

	if (!showFolio && !gdaConsecutivo) {
		return <span className="text-sm text-zinc-400 dark:text-slate-500">{emptyLabel}</span>;
	}

	return (
		<div className="flex flex-wrap items-center gap-2">
			{showFolio && (
				<span className="inline-flex items-center gap-1.5 rounded-full bg-lime-500/15 px-2 py-0.5 font-mono text-sm font-semibold text-lime-600 ring-1 ring-lime-400/30 dark:text-lime-400">
					<QrCodeIcon className="size-3.5 shrink-0" aria-hidden />
					<span>{purchase.gda_order_id}</span>
				</span>
			)}
			{gdaConsecutivo && (
				<span className="inline-flex items-center gap-1.5 rounded-full bg-blue-500/15 px-2 py-0.5 font-mono text-sm font-semibold text-blue-600 ring-1 ring-blue-400/30 dark:text-blue-400">
					<QrCodeIcon className="size-3.5 shrink-0" aria-hidden />
					<span>{gdaConsecutivo}</span>
				</span>
			)}
		</div>
	);
}
