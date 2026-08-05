import {
	ResponsiveContainer,
	ScatterChart,
	Scatter,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	ZAxis,
} from "recharts";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const BAND_COLORS = {
	excellent: DASHBOARD_COLORS.green,
	good: DASHBOARD_COLORS.blue,
	at_risk: DASHBOARD_COLORS.orange,
	critical: DASHBOARD_COLORS.red,
	lost: DASHBOARD_COLORS.slate,
};

export default function HealthScatter({ data = [] }) {
	const groups = Object.keys(BAND_COLORS).map((band) => ({
		band,
		points: data.filter((d) => d.band === band),
	}));

	return (
		<ChartCard
			title="Health Score vs Lifetime Value"
			description="Detecta VIP, Premium, recuperables y en riesgo."
		>
			<div className={`h-80 w-full ${CHART_UI}`}>
				{data.length ? (
					<ResponsiveContainer width="100%" height="100%">
						<ScatterChart margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis
								type="number"
								dataKey="health_score"
								name="Health"
								domain={[0, 100]}
								tick={{ fontSize: 11 }}
							/>
							<YAxis
								type="number"
								dataKey="ltv"
								name="LTV"
								tick={{ fontSize: 11 }}
								width={48}
								tickFormatter={(v) => `$${Math.round(v / 1000)}k`}
							/>
							<ZAxis range={[40, 120]} />
							<Tooltip
								cursor={{ strokeDasharray: "3 3" }}
								formatter={(value, name) =>
									name === "LTV"
										? [`$${Number(value).toLocaleString("es-MX")}`, name]
										: [value, name]
								}
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							{groups.map((group) =>
								group.points.length ? (
									<Scatter
										key={group.band}
										name={group.band}
										data={group.points}
										fill={BAND_COLORS[group.band]}
									/>
								) : null,
							)}
						</ScatterChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin puntos para scatter
					</div>
				)}
			</div>
		</ChartCard>
	);
}
