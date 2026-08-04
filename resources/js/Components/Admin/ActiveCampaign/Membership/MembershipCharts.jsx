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

function DualSeriesChart({ title, description, data, className }) {
	return (
		<ChartCard title={title} description={description} className={className}>
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
								dataKey="altas"
								name="Altas"
								stroke={DASHBOARD_COLORS.blue}
								strokeWidth={2}
								dot={false}
							/>
							<Line
								yAxisId="right"
								type="monotone"
								dataKey="ingresos"
								name="Ingresos $"
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

function BarSeriesChart({ title, description, data, truth = "disponible", className }) {
	return (
		<ChartCard title={title} description={description} className={className}>
			<div className="mb-2">
				<AnalyticsTruthBadge truth={truth} />
			</div>
			{data?.length ? (
				<div className="h-56">
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={data}>
							<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
							<XAxis
								dataKey="label"
								tick={{ fontSize: 11 }}
								interval={0}
								angle={-20}
								textAnchor="end"
								height={60}
							/>
							<YAxis yAxisId="left" allowDecimals={false} />
							<YAxis yAxisId="right" orientation="right" />
							<Tooltip />
							<Legend />
							<Bar
								yAxisId="left"
								dataKey="altas"
								name="Altas"
								fill={DASHBOARD_COLORS.blue}
								radius={[4, 4, 0, 0]}
							/>
							<Bar
								yAxisId="right"
								dataKey="ingresos"
								name="Ingresos $"
								fill={DASHBOARD_COLORS.green}
								radius={[4, 4, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				</div>
			) : (
				<p className="text-sm text-zinc-500">Sin datos en el periodo.</p>
			)}
		</ChartCard>
	);
}

export default function MembershipCharts({ charts = null }) {
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
					description="Altas e ingresos diarios (titulares)."
					data={charts.by_day}
				/>
				<DualSeriesChart
					title="Por semana"
					description="Agregación semanal del periodo."
					data={charts.by_week}
				/>
				<DualSeriesChart
					title="Por mes"
					description="Agregación mensual (útil en rangos largos)."
					data={charts.by_month}
				/>
				<BarSeriesChart
					title="Por tipo"
					description="Distribución por MedicalSubscriptionType."
					data={charts.by_type}
					truth="disponible"
				/>
				<BarSeriesChart
					title="Por ciudad"
					description="Ciudad vía addresses del customer (cobertura incompleta)."
					data={charts.by_city}
					truth="proxy"
					className="xl:col-span-2"
				/>
			</div>
		</section>
	);
}
