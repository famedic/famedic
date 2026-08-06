import {
	Bar,
	BarChart,
	CartesianGrid,
	Legend,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";
import { ChartCard, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

function Skeleton() {
	return (
		<div className="space-y-3" aria-busy="true">
			{Array.from({ length: 2 }).map((_, i) => (
				<div
					key={i}
					className="h-48 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

export default function FunnelsCharts({ charts = null }) {
	if (!charts) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Gráficas
				</h2>
				<Skeleton />
			</section>
		);
	}

	const bars = (charts.funnel_bars || []).map((row) => ({
		name: row.label,
		usuarios: row.value,
		truth: row.truth,
	}));

	const compare = (charts.funnel_compare || []).map((row) => ({
		name: row.label,
		actual: row.current,
		anterior: row.previous,
	}));

	const events = charts.events_by_type || [];

	return (
		<section className="space-y-4">
			<div className="flex flex-wrap items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Gráficas
				</h2>
				<AnalyticsTruthBadge truth="proxy" />
			</div>

			<div className="grid gap-4 xl:grid-cols-2">
				<ChartCard
					title="Usuarios por etapa"
					description="Solo etapas con volumen conocido aparecen con barra sólida."
				>
					{bars.some((b) => b.usuarios != null) ? (
						<div className="h-64">
							<ResponsiveContainer width="100%" height="100%">
								<BarChart data={bars} layout="vertical" margin={{ left: 8 }}>
									<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
									<XAxis type="number" allowDecimals={false} />
									<YAxis
										type="category"
										dataKey="name"
										width={100}
										tick={{ fontSize: 11 }}
									/>
									<Tooltip />
									<Bar
										dataKey="usuarios"
										fill={DASHBOARD_COLORS.blue}
										radius={[0, 4, 4, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					) : (
						<p className="text-sm text-zinc-500">
							Sin volúmenes numéricos para graficar en este funnel.
						</p>
					)}
				</ChartCard>

				<ChartCard
					title="Comparativo vs periodo anterior"
					description="Etapas con previous conocido (proxies Dashboard)."
				>
					{compare.length ? (
						<div className="h-64">
							<ResponsiveContainer width="100%" height="100%">
								<BarChart data={compare}>
									<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
									<XAxis dataKey="name" tick={{ fontSize: 11 }} />
									<YAxis allowDecimals={false} />
									<Tooltip />
									<Legend />
									<Bar
										dataKey="actual"
										name="Actual"
										fill={DASHBOARD_COLORS.blue}
										radius={[4, 4, 0, 0]}
									/>
									<Bar
										dataKey="anterior"
										name="Anterior"
										fill={DASHBOARD_COLORS.slate}
										radius={[4, 4, 0, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					) : (
						<p className="text-sm text-zinc-500">
							Sin etapas comparables en este funnel.
						</p>
					)}
				</ChartCard>

				<ChartCard
					title="Tendencia · dispatches / día"
					description="Serie operativa del Dashboard (contexto, no conversión)."
					className="xl:col-span-2"
				>
					{(charts.dispatches_by_day || []).length ? (
						<div className="h-56">
							<ResponsiveContainer width="100%" height="100%">
								<BarChart data={charts.dispatches_by_day}>
									<CartesianGrid strokeDasharray="3 3" opacity={0.3} />
									<XAxis dataKey="label" tick={{ fontSize: 11 }} />
									<YAxis allowDecimals={false} />
									<Tooltip />
									<Bar
										dataKey="value"
										name="Dispatches"
										fill={DASHBOARD_COLORS.purple}
										radius={[4, 4, 0, 0]}
									/>
								</BarChart>
							</ResponsiveContainer>
						</div>
					) : (
						<p className="text-sm text-zinc-500">Sin serie de dispatches.</p>
					)}
				</ChartCard>

				{events.length ? (
					<ChartCard
						title="Top event types (dispatches)"
						description="Contexto Event Center / Dashboard — no es embudo cohort."
						className="xl:col-span-2"
					>
						<ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
							{events.map((ev) => (
								<li
									key={ev.label}
									className="flex items-center justify-between rounded-lg border border-zinc-100 px-3 py-2 text-sm dark:border-zinc-800"
								>
									<span className="truncate text-zinc-600 dark:text-zinc-300">
										{ev.label}
									</span>
									<span className="font-semibold tabular-nums">
										{ev.value}
									</span>
								</li>
							))}
						</ul>
					</ChartCard>
				) : null}
			</div>
		</section>
	);
}
