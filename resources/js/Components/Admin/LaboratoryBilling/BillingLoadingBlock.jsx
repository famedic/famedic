import clsx from "clsx";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { billingPanelClass } from "./billingUi";

export function BillingMetricSkeleton({ count = 4 }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-hidden="true">
			{Array.from({ length: count }).map((_, index) => (
				<div key={index} className={billingPanelClass}>
					<div className="h-3 w-24 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
					<div className="mt-3 h-7 w-16 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
				</div>
			))}
		</div>
	);
}

export function BillingTableSkeleton({ rows = 5 }) {
	return (
		<div className={clsx(billingPanelClass, "space-y-3")} aria-hidden="true">
			{Array.from({ length: rows }).map((_, index) => (
				<div
					key={index}
					className="h-10 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-700/60"
				/>
			))}
		</div>
	);
}

export default function BillingLoadingBlock({
	processing = false,
	children,
	className,
	label = "Actualizando resultados…",
}) {
	return (
		<div className={clsx("relative", className)}>
			<div
				className={clsx(
					"transition-opacity duration-150",
					processing && "pointer-events-none opacity-50",
				)}
				aria-busy={processing || undefined}
			>
				{children}
			</div>
			{processing ? (
				<div
					className="pointer-events-none absolute inset-0 flex items-start justify-center pt-16"
					role="status"
					aria-live="polite"
				>
					<div className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white/95 px-3 py-2 text-sm text-zinc-700 shadow-sm dark:border-zinc-600/80 dark:bg-zinc-800/95 dark:text-zinc-200">
						<ArrowPathIcon className="size-4 animate-spin text-famedic-dark dark:text-famedic-lime" />
						{label}
					</div>
				</div>
			) : null}
		</div>
	);
}
