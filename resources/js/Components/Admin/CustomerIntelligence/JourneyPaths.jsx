import clsx from "clsx";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyPaths({ paths = [] }) {
	return (
		<ChartCard
			title="Customer paths"
			description="Rutas más frecuentes estimadas dentro del cohort."
		>
			<div className="space-y-3">
				{paths.map((path, index) => (
					<div
						key={path.id}
						className="rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-sky-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-sky-700"
					>
						<div className="flex items-start justify-between gap-3">
							<div>
								<p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
									Ruta {index + 1}
								</p>
								<div className="mt-2 flex flex-wrap items-center gap-1.5">
									{(path.steps || []).map((step, stepIndex) => (
										<div key={`${path.id}-${step}`} className="flex items-center gap-1.5">
											<span
												className={clsx(
													"rounded-md px-2 py-1 text-xs font-medium",
													step === "Abandono"
														? "bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"
														: step === "Compra"
															? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
															: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
												)}
											>
												{step}
											</span>
											{stepIndex < path.steps.length - 1 ? (
												<span className="text-zinc-300 dark:text-zinc-600">↓</span>
											) : null}
										</div>
									))}
								</div>
							</div>
							<div className="text-right">
								<p className="text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
									{path.percent_formatted}
								</p>
								<Badge color={path.converted ? "emerald" : "orange"} className="mt-1">
									{path.converted ? "Convierte" : "Abandona"}
								</Badge>
								<p className="mt-1 text-[11px] text-zinc-400">
									~{Number(path.users || 0).toLocaleString()} usuarios
								</p>
							</div>
						</div>
					</div>
				))}
				{paths.length === 0 ? (
					<p className="text-sm text-zinc-400">Sin rutas calculadas</p>
				) : null}
			</div>
		</ChartCard>
	);
}
