import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function SourceBarChart({ data = [], title = "Clientes dormidos por fuente" }) {
	return (
		<ChartCard
			title={title}
			description="Fuente derivada de tipo de cuenta y referidos."
		>
			<div className={`h-72 w-full ${CHART_UI}`}>
				{data.length ? (
					<ResponsiveContainer width="100%" height="100%">
						<BarChart
							data={data}
							layout="vertical"
							margin={{ top: 8, right: 16, left: 8, bottom: 0 }}
						>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis type="number" tick={{ fontSize: 11 }} allowDecimals={false} />
							<YAxis
								type="category"
								dataKey="label"
								width={110}
								tick={{ fontSize: 11 }}
							/>
							<Tooltip
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Bar
								dataKey="value"
								name="Dormidos"
								fill={DASHBOARD_COLORS.blue}
								radius={[0, 6, 6, 0]}
								barSize={18}
							/>
						</BarChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin datos
					</div>
				)}
			</div>
		</ChartCard>
	);
}
