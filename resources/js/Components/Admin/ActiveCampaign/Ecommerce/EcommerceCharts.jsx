import {
	Bar,
	BarChart,
	CartesianGrid,
	Legend,
	Line,
	LineChart,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";
import { ChartCard, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

function Skeleton() {
	return (
		<div className="grid gap-4 xl:grid-cols-2" aria-busy="true">
			{Array.from({ length: 4 }).map((_, i) => (
				<div
					key={i}
					className="h-56 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

function DualSeriesChart({ title, description, data }) {
	return (
		<ChartCard title={title} description={description}>
			{data?.length ? (
				<div className="h-56">
					<ResponsiveContainer width="100%" height="100%">
						<LineChart data={data}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis yAxisId="left" allowDecimals={false} />
							<YAxis yAxisId="right" orientation="right" />
							<Tooltip />
							<Legend />
							<Line
								yAxisId="left"
								type="monotone"
								dataKey="pedidos"
								name="Pedidos"
								stroke={DASHBOARD_COLORS.blue}
								strokeWidth={2}
								dot={false}
							/>
							<Line
								yAxisId="right"
								type="monotone"
								dataKey="gmv"
								name="GMV $"
								stroke={DASHBOARD_COLORS.green}
								strokeWidth={2}
								dot={false}
							/>
						</LineChart>
					</ResponsiveContainer>
				</div>
			) : (
				<p className="text-sm text-zinc-500">Sin datos en el periodo.</p>
			)}
		</ChartCard>
	);
}

export default function EcommerceCharts({ charts = null }) {
	if (!charts) {
		return (
			<section className="space-y-3">
				<div className="flex items-center gap-2">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Tendencias
					</h2>
					<AnalyticsTruthBadge truth="disponible" />
				</div>
				<Skeleton />
			</section>
		);
	}

	return (
		<section className="space-y-4">
			<div className="flex items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Tendencias
				</h2>
				<AnalyticsTruthBadge truth="disponible" />
			</div>

			<div className="grid gap-4 xl:grid-cols-2">
				<DualSeriesChart
					title="Por día"
					description="GMV y pedidos consolidados (3 canales)."
					data={charts.by_day}
				/>
				<DualSeriesChart
					title="Por semana"
					description="Agregación semanal."
					data={charts.by_week}
				/>
				<DualSeriesChart
					title="Por mes"
					description="Agregación mensual."
					data={charts.by_month}
				/>
				<ChartCard
					title="Por línea de negocio"
					description="GMV y pedidos del periodo por canal."
				>
					{(charts.by_channel || []).length ? (
						<div className="h-56">
							<ResponsiveContainer width="100%" height="100%">
								<BarChart data={charts.by_channel}>
									<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
									<XAxis dataKey="label" tick={{ fontSize: 11 }} />
									<YAxis yAxisId="left" allowDecimals={false} />
									<YAxis yAxisId="right" orientation="right" />
									<Tooltip />
									<Legend />
									<Bar
										yAxisId="left"
										dataKey="pedidos"
										name="Pedidos"
										fill={DASHBOARD_COLORS.blue}
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										yAxisId="right"
										dataKey="gmv"
										name="GMV $"
										fill={DASHBOARD_COLORS.green}
										radius={[4, 4, 0, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					) : (
						<p className="text-sm text-zinc-500">Sin datos.</p>
					)}
				</ChartCard>
			</div>
		</section>
	);
}
