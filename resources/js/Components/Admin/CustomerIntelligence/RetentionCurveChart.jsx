import { useMemo } from "react";
import {
	ResponsiveContainer,
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const COLORS = [
	DASHBOARD_COLORS.blue,
	DASHBOARD_COLORS.green,
	DASHBOARD_COLORS.orange,
	DASHBOARD_COLORS.purple,
	DASHBOARD_COLORS.red,
	DASHBOARD_COLORS.slate,
];

export default function RetentionCurveChart({ curves }) {
	const chartData = useMemo(() => {
		const average = curves?.average || [];
		const series = curves?.series || [];
		return average.map((point) => {
			const row = {
				week: point.week,
				label: `S${point.week}`,
				Promedio: point.percent,
			};
			series.forEach((serie) => {
				const match = (serie.points || []).find((p) => p.week === point.week);
				row[serie.label] = match?.percent ?? null;
			});
			return row;
		});
	}, [curves]);

	const seriesKeys = (curves?.series || []).map((s) => s.label);

	return (
		<ChartCard
			title="Retention curve"
			description="Comparación de cohortes por semana desde el registro."
		>
			<div className={`h-80 w-full ${CHART_UI}`}>
				{chartData.length ? (
					<ResponsiveContainer width="100%" height="100%">
						<LineChart data={chartData} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis
								tick={{ fontSize: 11 }}
								domain={[0, 100]}
								tickFormatter={(v) => `${v}%`}
								width={40}
							/>
							<Tooltip
								formatter={(value) =>
									value == null ? "—" : `${Number(value).toFixed(1)}%`
								}
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Legend wrapperStyle={{ fontSize: 11 }} />
							<Line
								type="monotone"
								dataKey="Promedio"
								stroke={DASHBOARD_COLORS.slate}
								strokeWidth={2.5}
								dot={false}
								strokeDasharray="4 4"
							/>
							{seriesKeys.map((key, index) => (
								<Line
									key={key}
									type="monotone"
									dataKey={key}
									stroke={COLORS[index % COLORS.length]}
									strokeWidth={1.75}
									dot={false}
								/>
							))}
						</LineChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin curvas de retención
					</div>
				)}
			</div>
		</ChartCard>
	);
}
