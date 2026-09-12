export default function MarketingCampaignWizardStepper({
	steps = [],
	currentStep = 0,
	onStepSelect,
}) {
	const total = steps.length;
	const progress = total <= 1 ? 100 : Math.round(((currentStep + 0.35) / total) * 100);

	return (
		<nav aria-label="Progreso del asistente" className="w-full select-none">
			<div className="mb-5 space-y-1.5">
				<div className="flex items-center justify-between gap-2 text-[11px] text-zinc-400">
					<span>Progreso general</span>
					<span className="font-medium text-zinc-500 tabular-nums">
						{Math.min(99, progress)}%
					</span>
				</div>
				<div
					className="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
					role="progressbar"
					aria-valuenow={progress}
					aria-valuemin={0}
					aria-valuemax={100}
				>
					<div
						className="h-full rounded-full bg-famedic-light transition-[width] duration-500 ease-out"
						style={{ width: `${progress}%` }}
					/>
				</div>
			</div>

			<ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
				{steps.map((step, index) => {
					const isCurrent = index === currentStep;
					const isDone = index < currentStep;
					const clickable =
						typeof onStepSelect === "function" && index <= currentStep;

					return (
						<li key={step.id}>
							<button
								type="button"
								disabled={!clickable}
								onClick={() => clickable && onStepSelect(index)}
								className={`flex w-full items-start gap-3 rounded-xl border px-3 py-3 text-left transition ${
									isCurrent
										? "border-famedic-light bg-famedic-light/10 dark:bg-famedic-light/10"
										: isDone
											? "border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/30"
											: "border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
								} ${clickable ? "cursor-pointer hover:shadow-sm" : "cursor-default opacity-80"}`}
								aria-current={isCurrent ? "step" : undefined}
							>
								<span
									className={`mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
										isCurrent
											? "bg-famedic-dark text-white dark:bg-famedic-light dark:text-zinc-950"
											: isDone
												? "bg-emerald-600 text-white"
												: "bg-zinc-100 text-zinc-500 dark:bg-zinc-800"
									}`}
								>
									{isDone ? "✓" : index + 1}
								</span>
								<span>
									<span className="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
										{step.label}
									</span>
									<span className="mt-0.5 block text-xs text-zinc-500">
										{isCurrent
											? "En curso"
											: isDone
												? "Completado"
												: "Pendiente"}
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
