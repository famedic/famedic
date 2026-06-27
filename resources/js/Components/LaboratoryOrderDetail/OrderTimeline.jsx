import {
	CheckCircleIcon,
	ClockIcon,
} from "@heroicons/react/24/outline";

export default function OrderTimeline({ steps }) {
	return (
		<div className="min-w-0 max-w-full space-y-4">
			<h3 className="break-words text-base font-semibold text-zinc-900 dark:text-white">Estado de la orden</h3>
			<div className="space-y-4">
				{steps.map((step, index) => {
					const completed = step.status === "completed";
					return (
						<div key={`${step.key}-${index}`} className="flex min-w-0 items-start gap-3">
							<div className="mt-0.5 shrink-0">
								{completed ? (
									<CheckCircleIcon className="size-5 text-emerald-500" />
								) : (
									<ClockIcon className="size-5 text-zinc-400" />
								)}
							</div>
							<div className="min-w-0 flex-1">
								<p className="min-w-0 flex-1 break-words text-sm font-medium text-zinc-900 dark:text-white">
									{step.title}
								</p>
								{step.description && (
									<p className="mt-1 break-words text-xs text-zinc-500 dark:text-slate-400">
										{step.description}
									</p>
								)}
							</div>
						</div>
					);
				})}
			</div>
		</div>
	);
}
