import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function RepeatPurchaseLadder({ steps = [] }) {
	const max = Math.max(...steps.map((s) => s.count || 0), 1);

	return (
		<ChartCard
			title="Repeat purchase ladder"
			description="Primera → segunda → tercera → cuarta → frecuente."
		>
			<div className="space-y-2.5">
				{steps.map((step, index) => {
					const width = Math.max(8, ((step.count || 0) / max) * 100);
					return (
						<div key={step.key} className="space-y-1">
							<div className="flex items-center justify-between gap-3 text-xs">
								<span className="font-medium text-zinc-800 dark:text-zinc-100">
									{step.label}
								</span>
								<span className="tabular-nums text-zinc-500">
									{Number(step.count || 0).toLocaleString()} · {step.percent}%
								</span>
							</div>
							<div className="h-8 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
								<div
									className={`flex h-full items-center rounded-lg px-3 text-xs font-semibold text-white transition-all duration-500 ${
										index === steps.length - 1 ? "bg-emerald-500" : "bg-violet-500"
									}`}
									style={{ width: `${width}%` }}
								>
									{step.percent}%
								</div>
							</div>
						</div>
					);
				})}
			</div>
		</ChartCard>
	);
}
