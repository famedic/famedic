import clsx from "clsx";
import { CheckIcon } from "@heroicons/react/24/solid";
import { Text } from "@/Components/Catalyst/text";

const STATUS_RING = {
	completed: "bg-emerald-500 text-white",
	current: "bg-sky-600 text-white ring-4 ring-sky-500/25 dark:bg-sky-500",
	pending: "bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400",
};

export default function CheckoutProgressTimeline({
	steps = [],
	draftUpdatedAt = null,
}) {
	if (!steps.length) {
		return null;
	}

	return (
		<div className="border-t border-sky-300/40 pt-4 dark:border-sky-700/50">
			<div className="mb-4 flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
					Progreso del checkout
				</p>
				{draftUpdatedAt && (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						Última actualización {draftUpdatedAt}
					</Text>
				)}
			</div>

			<nav aria-label="Progreso del checkout del paciente">
				<ol className="grid grid-cols-2 gap-x-2 gap-y-6 sm:grid-cols-3 lg:grid-cols-5">
					{steps.map((step, index) => {
						const isLast = index === steps.length - 1;
						const connectorDone =
							step.status === "completed" ||
							steps[index + 1]?.status === "completed" ||
							steps[index + 1]?.status === "current";

						return (
							<li
								key={step.id}
								className={clsx(
									"relative flex min-w-0 flex-col items-center px-1 text-center",
									!isLast &&
										"lg:after:absolute lg:after:left-[calc(50%+1rem)] lg:after:top-4 lg:after:h-0.5 lg:after:w-[calc(100%-2rem)] lg:after:content-['']",
									!isLast &&
										(connectorDone
											? "lg:after:bg-emerald-400 dark:lg:after:bg-emerald-500"
											: "lg:after:bg-zinc-200 dark:lg:after:bg-zinc-700"),
								)}
							>
								<div
									className={clsx(
										"relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold",
										STATUS_RING[step.status] ?? STATUS_RING.pending,
									)}
								>
									{step.status === "completed" ? (
										<CheckIcon className="size-4" aria-hidden />
									) : (
										index + 1
									)}
								</div>
								<p
									className={clsx(
										"mt-2 text-xs font-medium leading-tight",
										step.status === "current" &&
											"text-sky-700 dark:text-sky-300",
										step.status === "completed" &&
											"text-zinc-800 dark:text-zinc-200",
										step.status === "pending" &&
											"text-zinc-400 dark:text-zinc-500",
									)}
								>
									{step.label}
								</p>
								<p
									className={clsx(
										"mt-1 line-clamp-3 text-[10px] leading-snug sm:text-xs",
										step.detail
											? "text-zinc-600 dark:text-zinc-400"
											: "text-zinc-400 dark:text-zinc-500",
									)}
								>
									{step.detail ?? "Pendiente"}
								</p>
							</li>
						);
					})}
				</ol>
			</nav>
		</div>
	);
}
