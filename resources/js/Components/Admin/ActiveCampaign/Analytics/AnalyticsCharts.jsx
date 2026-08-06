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
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme";
import { Text } from "@/Components/Catalyst/text";

function Empty() {
	return <Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>;
}

function AreaSeries({ data, color }) {
	if (!data?.length) return <Empty />;
	return (
		<div className={`h-48 w-full ${CHART_UI}`}>
			<ResponsiveContainer width="100%" height="100%">
				<AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
					<CartesianGrid strokeDasharray="3 3" vertical={false} />
					<XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} />
					<YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} width={32} />
					<Tooltip />
					<Area
						type="monotone"
						dataKey="value"
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

function EventsBar({ data }) {
	if (!data?.length) return <Empty />;
	return (
		<div className={`h-48 w-full ${CHART_UI}`}>
			<ResponsiveContainer width="100%" height="100%">
				<BarChart data={data} layout="vertical" margin={{ top: 4, right: 12, left: 8, bottom: 4 }}>
					<CartesianGrid strokeDasharray="3 3" horizontal={false} />
					<XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} fontSize={11} />
					<YAxis type="category" dataKey="label" width={110} tickLine={false} axisLine={false} fontSize={10} />
					<Tooltip />
					<Bar dataKey="value" fill={DASHBOARD_COLORS.purple} radius={[0, 4, 4, 0]} isAnimationActive={false} />
				</BarChart>
			</ResponsiveContainer>
		</div>
	);
}

const CHART_META = {
	sync_by_day: {
		title: "Sync por día",
		description: "Dispatches synced (misma serie del Dashboard).",
		color: DASHBOARD_COLORS.green,
		kind: "area",
	},
	errors_by_day: {
		title: "Errores por día",
		description: "Dispatches failed (misma serie del Dashboard).",
		color: DASHBOARD_COLORS.red,
		kind: "area",
	},
	dispatches_by_day: {
		title: "Dispatches creados",
		description: "Encolado diario (misma serie del Dashboard).",
		color: DASHBOARD_COLORS.blue,
		kind: "area",
	},
	events_by_type: {
		title: "Top event types",
		description: "Ranking de event_type (misma serie del Dashboard).",
		kind: "bar",
	},
};

export default function AnalyticsCharts({ chartKeys = [], charts = null }) {
	if (!chartKeys.length) {
		return (
			<div className="rounded-xl border border-dashed border-zinc-200 p-4 text-sm text-zinc-500 dark:border-zinc-700">
				Este dominio no tiene gráficas agregadas reutilizables todavía.
			</div>
		);
	}

	if (!charts) {
		return (
			<div className="grid gap-4 lg:grid-cols-2" aria-busy="true">
				{chartKeys.map((key) => (
					<div
						key={key}
						className="h-56 animate-pulse rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
					/>
				))}
			</div>
		);
	}

	return (
		<div className="grid gap-4 lg:grid-cols-2">
			{chartKeys.map((key) => {
				const meta = CHART_META[key];
				if (!meta) return null;
				const data = charts[key];
				return (
					<ChartCard key={key} title={meta.title} description={meta.description}>
						{meta.kind === "bar" ? (
							<EventsBar data={data} />
						) : (
							<AreaSeries data={data} color={meta.color} />
						)}
					</ChartCard>
				);
			})}
		</div>
	);
}
