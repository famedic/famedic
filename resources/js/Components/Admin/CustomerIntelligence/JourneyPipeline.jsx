import clsx from "clsx";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyPipeline({ stages = [] }) {
	return (
		<ChartCard
			title="Journey visual"
			description="Pipeline de activación. Cada card muestra volumen, % del cohort, conversión y abandono."
		>
			<div className="flex gap-3 overflow-x-auto pb-2 pt-1">
				{stages.map((stage, index) => (
					<div key={stage.key} className="flex items-stretch gap-3">
						<div
							className={clsx(
								"w-44 shrink-0 rounded-xl border bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:bg-zinc-900",
								index === 0
									? "border-sky-300 dark:border-sky-700"
									: stage.key === "first_purchase" || stage.key === "frequent"
										? "border-emerald-300 dark:border-emerald-700"
										: "border-zinc-200 dark:border-zinc-700",
							)}
						>
							<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">
								{stage.label}
							</p>
							<p className="mt-2 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{stage.count_formatted}
							</p>
							<p className="mt-1 text-sm font-medium text-sky-600 dark:text-sky-400">
								{stage.percent_formatted} del cohort
							</p>
							{stage.conversion_from_previous != null ? (
								<p className="mt-2 text-[11px] text-zinc-500">
									Conv. etapa:{" "}
									<span className="font-semibold text-zinc-800 dark:text-zinc-200">
										{stage.conversion_from_previous}%
									</span>
								</p>
							) : (
								<p className="mt-2 text-[11px] text-zinc-400">Etapa inicial</p>
							)}
							{stage.dropoff_percent != null && stage.dropoff_percent > 0 ? (
								<p className="text-[11px] text-rose-500">
									Abandono: {stage.dropoff_percent}%
								</p>
							) : null}
							{stage.avg_days_to_next != null ? (
								<p className="mt-1 text-[11px] text-zinc-400">
									→ siguiente: {stage.avg_days_to_next}d
								</p>
							) : null}
						</div>
						{index < stages.length - 1 ? (
							<div className="flex w-16 shrink-0 flex-col items-center justify-center text-center">
								<div className="h-px w-full bg-gradient-to-r from-zinc-300 to-zinc-200 dark:from-zinc-600 dark:to-zinc-700" />
								{stages[index + 1]?.dropoff_percent != null ? (
									<p className="mt-1 text-[10px] font-medium text-rose-500">
										−{stages[index + 1].dropoff_percent}%
									</p>
								) : null}
								{stage.avg_days_to_next != null ? (
									<p className="text-[10px] text-zinc-400">
										{stage.avg_days_to_next}d
									</p>
								) : null}
								<p className="text-[10px] text-zinc-300 dark:text-zinc-600">↓</p>
							</div>
						) : null}
					</div>
				))}
			</div>
		</ChartCard>
	);
}
