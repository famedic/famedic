import clsx from "clsx";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function ConversionFunnel({ data = [] }) {
	const max = Math.max(...data.map((s) => s.value || 0), 1);

	return (
		<ChartCard
			title="Embudo de activación"
			description="Registro → verificación → carrito → checkout → primera compra."
		>
			<div className="space-y-3">
				{data.map((stage, index) => {
					const width = Math.max(12, (stage.value / max) * 100);
					return (
						<div key={stage.stage} className="space-y-1.5">
							<div className="flex items-center justify-between gap-3 text-xs">
								<span className="font-medium text-zinc-800 dark:text-zinc-100">
									{stage.label}
								</span>
								<span className="tabular-nums text-zinc-500">
									{Number(stage.value || 0).toLocaleString()}
									{stage.dropoff_percent != null ? (
										<span className="ml-2 text-rose-500">
											−{stage.dropoff_percent}% abandono
										</span>
									) : null}
								</span>
							</div>
							<div className="h-9 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
								<div
									className={clsx(
										"flex h-full items-center rounded-lg px-3 text-xs font-semibold text-white transition-all duration-500",
										index === data.length - 1
											? "bg-emerald-500"
											: "bg-sky-500",
									)}
									style={{ width: `${width}%` }}
								>
									{index === 0
										? "100%"
										: `${((stage.value / (data[0]?.value || 1)) * 100).toFixed(1)}%`}
								</div>
							</div>
						</div>
					);
				})}
			</div>
		</ChartCard>
	);
}
