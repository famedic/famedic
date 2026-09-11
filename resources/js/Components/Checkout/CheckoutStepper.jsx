import clsx from "clsx";
import { CheckIcon } from "@heroicons/react/24/solid";

export default function CheckoutStepper({ steps, currentStep }) {
	const activeStep = steps[currentStep] ?? steps[0];

	return (
		<nav aria-label="Progreso del checkout" className="mb-8">
			<ol className="flex items-start justify-between">
				{steps.map((step, index) => {
					const isCompleted = index < currentStep;
					const isCurrent = index === currentStep;
					const isLast = index === steps.length - 1;
					const stateLabel = isCompleted
						? "completado"
						: isCurrent
							? "actual"
							: "pendiente";

					return (
						<li
							key={step.id}
							className={clsx(
								"flex flex-1 items-start",
								!isLast &&
									"after:mx-2 after:mt-4 after:h-0.5 after:flex-1 after:content-[''] sm:after:mx-4 sm:after:mt-[18px]",
								!isLast &&
									(isCompleted
										? "after:bg-famedic-dark dark:after:bg-famedic-lime"
										: "after:bg-zinc-200 dark:after:bg-slate-700"),
							)}
							aria-label={`Paso ${index + 1} de ${steps.length}: ${step.ariaLabel ?? step.label}, ${stateLabel}`}
							aria-current={isCurrent ? "step" : undefined}
						>
							<div className="flex min-w-0 flex-col items-center gap-1.5 sm:w-24 sm:gap-2 lg:w-28">
								<div
									className={clsx(
										"flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition-colors sm:size-9",
										isCompleted &&
											"bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker",
										isCurrent &&
											!isCompleted &&
											"bg-famedic-dark text-white ring-4 ring-famedic-dark/20 dark:bg-famedic-lime dark:text-famedic-darker dark:ring-famedic-lime/20",
										!isCompleted &&
											!isCurrent &&
											"bg-zinc-100 text-zinc-500 dark:bg-slate-800 dark:text-slate-400",
									)}
								>
									{isCompleted ? (
										<>
											<CheckIcon
												className="size-4 sm:size-5"
												aria-hidden="true"
											/>
											<span className="sr-only">
												Completado
											</span>
										</>
									) : (
										step.number
									)}
								</div>
								<span
									className={clsx(
										"hidden min-h-10 max-w-24 text-center text-xs font-medium leading-5 sm:block lg:max-w-28",
										isCurrent
											? "text-famedic-dark dark:text-famedic-lime"
											: isCompleted
												? "text-zinc-700 dark:text-slate-300"
												: "text-zinc-400 dark:text-slate-500",
									)}
								>
									{step.label}
								</span>
							</div>
						</li>
					);
				})}
			</ol>

			{activeStep && (
				<p className="mt-3 text-center text-sm font-medium text-famedic-dark sm:hidden dark:text-famedic-lime">
					<span className="block text-xs font-normal text-zinc-500 dark:text-slate-400">
						Paso {currentStep + 1} de {steps.length}
					</span>
					{activeStep.label}
				</p>
			)}
		</nav>
	);
}
