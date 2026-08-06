import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import {
	Bar,
	BarChart,
	Cell,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";

function formatMs(ms) {
	if (ms == null) return "—";
	if (ms < 1000) return `${Math.round(ms)} ms`;
	return `${(ms / 1000).toFixed(1)} s`;
}

const COLORS = ["#0f766e", "#64748b", "#2563eb", "#7c3aed", "#059669"];

export default function PerformanceModule({ data }) {
	const stages = data?.stages || [];
	const funnel = data?.funnel || [];
	const chartData = stages.map((s) => ({
		label: s.label.replace("Tiempo ", ""),
		avg_s: s.avg_ms != null ? Number((s.avg_ms / 1000).toFixed(2)) : 0,
		has_data: s.avg_ms != null,
	}));

	return (
		<div className="space-y-6">
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
				{stages.map((stage) => (
					<BillingMetricCard
						key={stage.id}
						label={stage.label}
						value={formatMs(stage.avg_ms)}
						hint={
							stage.note ||
							(stage.samples
								? `${stage.samples} muestras · ${stage.truth}`
								: stage.truth)
						}
						tone={stage.avg_ms != null ? "sky" : "zinc"}
					/>
				))}
			</div>

			<div className="grid gap-4 lg:grid-cols-2">
				<ChartCard
					title="Latencias promedio"
					description="OpenAI · Matching · Validación · Checkout · Compra"
				>
					<div className="h-56">
						<ResponsiveContainer width="100%" height="100%">
							<BarChart data={chartData}>
								<XAxis dataKey="label" tick={{ fontSize: 10 }} />
								<YAxis
									tick={{ fontSize: 11 }}
									unit="s"
									allowDecimals
								/>
								<Tooltip
									formatter={(value, _n, item) =>
										item?.payload?.has_data
											? [`${value} s`, "Promedio"]
											: ["Sin datos", "Promedio"]
									}
								/>
								<Bar dataKey="avg_s" radius={[6, 6, 0, 0]}>
									{chartData.map((entry, index) => (
										<Cell
											key={entry.label}
											fill={
												entry.has_data
													? COLORS[index % COLORS.length]
													: "#d4d4d8"
											}
										/>
									))}
								</Bar>
							</BarChart>
						</ResponsiveContainer>
					</div>
				</ChartCard>

				<ChartCard title="Embudo completo" description="Volumen por etapa">
					<ol className="space-y-3">
						{funnel.map((step, index) => (
							<li key={step.id} className="flex items-center gap-3 text-sm">
								<span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-[11px] font-bold text-white dark:bg-zinc-100 dark:text-zinc-900">
									{index + 1}
								</span>
								<span className="flex-1 font-medium">{step.label}</span>
								<span className="tabular-nums text-zinc-500">{step.count}</span>
							</li>
						))}
					</ol>
				</ChartCard>
			</div>
		</div>
	);
}
