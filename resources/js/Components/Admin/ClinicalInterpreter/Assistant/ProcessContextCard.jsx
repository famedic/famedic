import { WIZARD_STAGES } from "../productScope";

/**
 * Operator context card — estimated time + process overview.
 * Quiet, informational only.
 */
export default function ProcessContextCard() {
	return (
		<aside className="rounded-xl border border-zinc-200/80 bg-zinc-50/90 px-4 py-3.5 dark:border-zinc-700/80 dark:bg-zinc-950/50 sm:px-5">
			<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
				<div>
					<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Tiempo estimado
					</p>
					<p className="mt-0.5 text-sm font-medium text-zinc-800 dark:text-zinc-100">
						≈ 2 minutos
					</p>
				</div>
				<div className="sm:text-right">
					<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Pasos del proceso
					</p>
					<p className="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">
						{WIZARD_STAGES.map((s) => s.label).join(" · ")}
					</p>
				</div>
			</div>
		</aside>
	);
}
