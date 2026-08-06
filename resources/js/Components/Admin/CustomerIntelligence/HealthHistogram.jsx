import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Cell,
} from "recharts";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const COLORS = [
	DASHBOARD_COLORS.red,
	DASHBOARD_COLORS.orange,
	DASHBOARD_COLORS.slate,
	DASHBOARD_COLORS.blue,
	DASHBOARD_COLORS.green,
];

export default function HealthHistogram({ data = [] }) {
	return (
		<ChartCard
			title="Distribución de scores"
			description="Histograma 0–20 · 21–40 · 41–60 · 61–80 · 81–100"
		>
			<div className={`h-72 w-full ${CHART_UI}`}>
				{data.some((d) => d.count > 0) ? (
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis tick={{ fontSize: 11 }} width={36} allowDecimals={false} />
							<Tooltip
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Bar dataKey="count" name="Clientes" radius={[6, 6, 0, 0]}>
								{data.map((entry, index) => (
									<Cell key={entry.key} fill={COLORS[index % COLORS.length]} />
								))}
							</Bar>
						</BarChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin distribución
					</div>
				)}
			</div>
		</ChartCard>
	);
}
