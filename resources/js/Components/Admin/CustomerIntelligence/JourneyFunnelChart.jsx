import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyFunnelChart({ data = [], title = "Funnel compacto" }) {
	const max = Math.max(...data.map((s) => s.count || 0), 1);

	return (
		<ChartCard
			title={title}
			description="Registro → email → login → carrito → checkout → compra."
		>
			<div className="space-y-2.5">
				{data.map((stage, index) => {
					const width = Math.max(10, ((stage.count || 0) / max) * 100);
					return (
						<div key={stage.key} className="space-y-1">
							<div className="flex items-center justify-between gap-3 text-xs">
								<span className="font-medium text-zinc-800 dark:text-zinc-100">
									{stage.label}
								</span>
								<span className="tabular-nums text-zinc-500">
									{stage.count_formatted} · {stage.percent_formatted}
									{stage.dropoff_percent != null ? (
										<span className="ml-2 text-rose-500">
											−{stage.dropoff_percent}%
										</span>
									) : null}
								</span>
							</div>
							<div className="h-8 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
								<div
									className={`flex h-full items-center rounded-lg px-3 text-xs font-semibold text-white transition-all duration-500 ${
										index === data.length - 1 ? "bg-emerald-500" : "bg-sky-500"
									}`}
									style={{ width: `${width}%` }}
								>
									{stage.percent_formatted}
								</div>
							</div>
						</div>
					);
				})}
			</div>
		</ChartCard>
	);
}
