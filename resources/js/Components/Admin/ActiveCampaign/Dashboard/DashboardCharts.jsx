import {
	ResponsiveContainer,
	AreaChart,
	Area,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
} from "recharts";
import { usePage } from "@inertiajs/react";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme";
import SectionHeading, { QuietLink } from "./SectionHeading";

function EmptyChart({ label = "Sin datos en el periodo." }) {
	return (
		<div className="flex h-52 items-center justify-center text-sm text-zinc-400">
			{label}
		</div>
	);
}

function DailyAreaChart({ data, color, emptyLabel }) {
	if (!data?.length || data.every((d) => !d.value)) {
		return <EmptyChart label={emptyLabel} />;
	}

	return (
		<div className={`h-52 w-full ${CHART_UI}`}>
			<ResponsiveContainer width="100%" height="100%">
				<AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
					<CartesianGrid strokeDasharray="3 3" vertical={false} />
					<XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} />
					<YAxis
						allowDecimals={false}
						tickLine={false}
						axisLine={false}
						fontSize={11}
						width={32}
					/>
					<Tooltip
						contentStyle={{
							borderRadius: 8,
							border: "1px solid rgb(228 228 231)",
							fontSize: 12,
						}}
					/>
					<Area
						type="monotone"
						dataKey="value"
						name="Total"
						stroke={color}
						fill={color}
						fillOpacity={0.15}
						strokeWidth={2}
						isAnimationActive={false}
					/>
				</AreaChart>
			</ResponsiveContainer>
		</div>
	);
}

function EventsBarChart({ data }) {
	if (!data?.length) {
		return <EmptyChart label="Sin eventos en el periodo." />;
	}

	return (
		<div className={`h-52 w-full ${CHART_UI}`}>
			<ResponsiveContainer width="100%" height="100%">
				<BarChart
					data={data}
					layout="vertical"
					margin={{ top: 4, right: 12, left: 8, bottom: 4 }}
				>
					<CartesianGrid strokeDasharray="3 3" horizontal={false} />
					<XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} />
					<YAxis
						type="category"
						dataKey="label"
						width={110}
						tickLine={false}
						axisLine={false}
						fontSize={10}
					/>
					<Tooltip
						contentStyle={{
							borderRadius: 8,
							border: "1px solid rgb(228 228 231)",
							fontSize: 12,
						}}
					/>
					<Bar
						dataKey="value"
						name="Dispatches"
						fill={DASHBOARD_COLORS.purple}
						radius={[0, 4, 4, 0]}
						isAnimationActive={false}
					/>
				</BarChart>
			</ResponsiveContainer>
		</div>
	);
}

export function DashboardChartsSkeleton() {
	return (
		<section className="space-y-4" aria-busy="true" aria-label="Cargando gráficas">
			<SectionHeading
				eyebrow="Tendencias"
				title="Visualizaciones"
				description="Cargando series diferidas…"
			/>
			<div className="grid gap-4 lg:grid-cols-2">
				{[0, 1, 2, 3].map((i) => (
					<div
						key={i}
						className="h-64 animate-pulse rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
					/>
				))}
			</div>
		</section>
	);
}

/**
 * Lee `charts` desde page props (payload diferido Inertia).
 */
export default function DashboardCharts({ eventsUrl }) {
	const { charts } = usePage().props;

	return (
		<section className="space-y-4">
			<SectionHeading
				eyebrow="Tendencias"
				title="Visualizaciones"
				description="Sync, errores, volumen de dispatches y top de event_type."
				action={
					eventsUrl ? (
						<QuietLink href={eventsUrl}>Catálogo de eventos</QuietLink>
					) : null
				}
			/>

			<div className="grid gap-4 lg:grid-cols-2">
				<ChartCard
					title="Sync por día"
					description="Dispatches con status synced (synced_at)."
				>
					<DailyAreaChart
						data={charts?.sync_by_day}
						color={DASHBOARD_COLORS.green}
						emptyLabel="Sin sincronizaciones en el periodo."
					/>
				</ChartCard>

				<ChartCard
					title="Errores por día"
					description="Dispatches failed (updated_at)."
				>
					<DailyAreaChart
						data={charts?.errors_by_day}
						color={DASHBOARD_COLORS.red}
						emptyLabel="Sin errores en el periodo."
					/>
				</ChartCard>

				<ChartCard
					title="Dispatches creados"
					description="Volumen diario de encolado (created_at)."
				>
					<DailyAreaChart
						data={charts?.dispatches_by_day}
						color={DASHBOARD_COLORS.blue}
						emptyLabel="Sin dispatches en el periodo."
					/>
				</ChartCard>

				<ChartCard
					title="Top event types"
					description="Hasta 8 tipos más frecuentes del periodo."
				>
					<EventsBarChart data={charts?.events_by_type} />
				</ChartCard>
			</div>
		</section>
	);
}
