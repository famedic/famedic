import { Subheading } from "@/Components/Catalyst/heading";

export default function CheckoutProgress({ checkout }) {
	if (!checkout) {
		return null;
	}

	const progress = clampProgress(checkout.progress);
	const stepNumber = checkout.step_number ?? 1;
	const totalSteps = checkout.total_steps ?? 4;
	const stepName = checkout.step_name || "Continuar";

	return (
		<div className="space-y-2">
			<div className="flex flex-wrap items-end justify-between gap-2">
				<div>
					<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
						Avance
					</p>
					<Subheading level={3} className="mt-0.5 text-base">
						Paso {stepNumber} de {totalSteps}
					</Subheading>
				</div>
				<p className="text-sm font-medium text-famedic-darker dark:text-white">
					{stepName}
				</p>
			</div>

			<div
				className="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-slate-800"
				role="progressbar"
				aria-valuenow={progress}
				aria-valuemin="0"
				aria-valuemax="100"
				aria-label={`Progreso del checkout: ${progress}%`}
			>
				<div
					className="h-full rounded-full bg-famedic-light"
					style={{ width: `${progress}%` }}
				/>
			</div>
		</div>
	);
}

function clampProgress(value) {
	const number = Number(value);
	if (!Number.isFinite(number)) return 0;
	return Math.min(100, Math.max(0, number));
}
