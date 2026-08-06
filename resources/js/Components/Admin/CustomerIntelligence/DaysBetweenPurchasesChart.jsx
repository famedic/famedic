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

export default function DaysBetweenPurchasesChart({ data = [] }) {
	return (
		<ChartCard
			title="Tiempo entre compras"
			description="Distribución de gaps entre compras consecutivas."
		>
			<div className={`h-72 w-full ${CHART_UI}`}>
				{data.some((d) => d.count > 0) ? (
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis dataKey="label" tick={{ fontSize: 10 }} interval={0} angle={-15} textAnchor="end" height={50} />
							<YAxis tick={{ fontSize: 11 }} width={36} allowDecimals={false} />
							<Tooltip
								formatter={(value, _name, props) => [
									`${Number(value).toLocaleString()} (${props.payload.percent}%)`,
									"Compras",
								]}
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Bar
								dataKey="count"
								fill={DASHBOARD_COLORS.orange}
								radius={[6, 6, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin gaps calculables (hace falta 2+ compras)
					</div>
				)}
			</div>
		</ChartCard>
	);
}
