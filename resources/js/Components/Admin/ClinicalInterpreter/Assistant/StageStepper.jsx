import { WIZARD_STAGES } from "../productScope";

function stageStatus(index, currentIndex, completedStageIds, stageId) {
	if (index < currentIndex || completedStageIds.includes(stageId)) {
		if (index === currentIndex) {
			return { key: "current", label: "En curso" };
		}
		return { key: "done", label: "Completada" };
	}
	if (index === currentIndex) {
		return { key: "current", label: "En curso" };
	}
	return { key: "pending", label: "Pendiente" };
}

/**
 * 3-stage stepper + overall progress bar (companion, not replacement).
 */
export default function StageStepper({
	currentStageId = "interpret",
	completedStageIds = [],
	onStageSelect,
}) {
	const currentIndex = Math.max(
		0,
		WIZARD_STAGES.findIndex((s) => s.id === currentStageId),
	);
	const total = WIZARD_STAGES.length;
	// Overall progress: current stage counts as in-progress slice
	const overallPercent = Math.round(((currentIndex + 0.35) / total) * 100);
	const clampedPercent = Math.min(100, Math.max(8, overallPercent));

	return (
		<nav aria-label="Progreso del asistente" className="w-full select-none">
			{/* Overall progress bar — accompanies stepper */}
			<div className="mb-5 space-y-1.5">
				<div className="flex items-center justify-between gap-2 text-[11px] text-zinc-400">
					<span>Progreso general</span>
					<span className="font-medium text-zinc-500 tabular-nums">
						{Math.min(99, clampedPercent)}%
					</span>
				</div>
				<div
					className="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
					role="progressbar"
					aria-valuenow={clampedPercent}
					aria-valuemin={0}
					aria-valuemax={100}
					aria-label="Progreso general del asistente"
				>
					<div
						className="h-full rounded-full bg-famedic-light transition-[width] duration-500 ease-out"
						style={{ width: `${clampedPercent}%` }}
					/>
				</div>
			</div>

			<ol className="relative grid grid-cols-3 gap-2 sm:gap-4">
				{WIZARD_STAGES.map((stage, index) => {
					const status = stageStatus(
						index,
						currentIndex,
						completedStageIds,
						stage.id,
					);
					const isCurrent = status.key === "current";
					const isDone = status.key === "done";
					const isPending = status.key === "pending";
					const clickable =
						typeof onStageSelect === "function" &&
						(isDone || isCurrent);

					return (
						<li key={stage.id} className="relative flex justify-center">
							{index < total - 1 && (
								<span
									aria-hidden
									className={`absolute left-[calc(50%+1.25rem)] right-[-50%] top-4 h-0.5 sm:hidden ${
										index < currentIndex
											? "bg-emerald-500/80"
											: "bg-zinc-200 dark:bg-zinc-700"
									}`}
								/>
							)}

							<button
								type="button"
								disabled={!clickable}
								onClick={() => clickable && onStageSelect?.(stage.id)}
								title={stage.description}
								className={`relative z-[1] flex w-full max-w-[10rem] flex-col items-center gap-2 rounded-xl px-1 py-2 transition duration-200 ${
									isCurrent
										? "bg-famedic-light/10 dark:bg-famedic-light/15"
										: clickable
											? "hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
											: ""
								} ${clickable ? "cursor-pointer" : "cursor-default"} ${
									isPending ? "opacity-75" : ""
								}`}
								aria-current={isCurrent ? "step" : undefined}
							>
								<span
									className={`flex size-9 items-center justify-center rounded-full text-sm font-semibold transition duration-300 ${
										isCurrent
											? "bg-famedic-dark text-white shadow-sm ring-4 ring-famedic-light/30 dark:bg-famedic-light dark:text-zinc-950 dark:ring-famedic-light/20"
											: isDone
												? "bg-emerald-600 text-white dark:bg-emerald-500"
												: "border border-zinc-200 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-500"
									}`}
								>
									{isDone ? (
										<svg
											viewBox="0 0 16 16"
											fill="currentColor"
											className="size-4"
											aria-hidden
										>
											<path
												fillRule="evenodd"
												d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z"
												clipRule="evenodd"
											/>
										</svg>
									) : (
										stage.number
									)}
								</span>
								<span className="text-center">
									<span
										className={`block text-xs font-semibold tracking-tight sm:text-sm ${
											isCurrent
												? "text-famedic-dark dark:text-famedic-light"
												: isDone
													? "text-zinc-700 dark:text-zinc-200"
													: "text-zinc-400"
										}`}
									>
										{stage.label}
									</span>
									<span
										className={`mt-0.5 block text-[10px] font-medium leading-snug sm:text-[11px] ${
											isCurrent
												? "text-famedic-dark/80 dark:text-famedic-light/80"
												: isDone
													? "text-emerald-700 dark:text-emerald-400"
													: "text-zinc-400"
										}`}
									>
										{status.label}
									</span>
								</span>
							</button>
						</li>
					);
				})}
			</ol>
		</nav>
	);
}
