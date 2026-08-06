import { Badge } from "@/Components/Catalyst/badge";

export default function ValidationProgressBar({
	stages = [],
	percent = 0,
}) {
	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				<div className="flex flex-wrap items-center gap-2">
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Human Validation Center
					</p>
					{stages.map((stage) => (
						<Badge
							key={stage.id}
							color={stage.done ? "emerald" : "zinc"}
							className="!text-[10px]"
						>
							{stage.label}
							{stage.id === "validation" && !stage.done
								? ` ${stage.percent ?? 0}%`
								: stage.done
									? " ✓"
									: ""}
						</Badge>
					))}
				</div>
				<div className="min-w-[180px] flex-1 lg:max-w-xs">
					<div className="mb-1 flex justify-between text-[11px] text-zinc-500">
						<span>Porcentaje completado</span>
						<span className="font-semibold text-zinc-800 dark:text-zinc-100">
							{percent}%
						</span>
					</div>
					<div className="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
						<div
							className="h-full rounded-full bg-famedic-light transition-all duration-300"
							style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
						/>
					</div>
				</div>
			</div>
		</section>
	);
}
