import { InformationCircleIcon } from "@heroicons/react/16/solid";
import clsx from "clsx";
import { billingPanelClass, billingValueToneClass } from "./billingUi";

/**
 * Tarjeta de métrica compacta, mismo lenguaje visual que Monitoreo · Carritos.
 * Fondo neutro + valor con tono semántico (no rellenos de color por tarjeta).
 */
export default function BillingMetricCard({
	label,
	value,
	hint,
	help,
	tone = "default",
	deltaPercent = null,
	icon: Icon = null,
	className,
}) {
	const deltaLabel =
		deltaPercent === null || deltaPercent === undefined
			? null
			: `${deltaPercent > 0 ? "+" : ""}${deltaPercent}% vs periodo anterior`;

	return (
		<div className={clsx(billingPanelClass, "min-w-0", className)}>
			<div className="flex min-w-0 items-center gap-2">
				{Icon ? (
					<Icon
						className="size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
						aria-hidden="true"
					/>
				) : null}
				<p className="min-w-0 truncate text-xs font-medium leading-snug text-zinc-600 dark:text-zinc-300">
					{label}
				</p>
				{help ? (
					<span title={help} aria-label={help} className="shrink-0">
						<InformationCircleIcon className="size-4 text-zinc-400 dark:text-zinc-500" />
					</span>
				) : null}
			</div>

			<p
				className={clsx(
					"mt-1 text-2xl font-semibold tabular-nums",
					billingValueToneClass[tone] ?? billingValueToneClass.default,
				)}
			>
				{value ?? "—"}
			</p>

			{deltaLabel ? (
				<p
					className={clsx(
						"mt-1 text-[11px] font-medium leading-snug",
						deltaPercent > 0
							? "text-emerald-700 dark:text-emerald-400"
							: deltaPercent < 0
								? "text-red-700 dark:text-red-400"
								: "text-zinc-500 dark:text-zinc-400",
					)}
				>
					{deltaLabel}
				</p>
			) : null}

			{hint ? (
				<p className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{hint}</p>
			) : null}
		</div>
	);
}
