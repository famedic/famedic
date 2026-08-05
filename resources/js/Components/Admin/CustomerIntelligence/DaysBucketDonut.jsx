import {
	ResponsiveContainer,
	PieChart,
	Pie,
	Cell,
	Tooltip,
	Legend,
} from "recharts";
import {
	ChartCard,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const COLORS = [
	DASHBOARD_COLORS.green,
	DASHBOARD_COLORS.blue,
	DASHBOARD_COLORS.purple,
	DASHBOARD_COLORS.orange,
	DASHBOARD_COLORS.red,
];

export default function DaysBucketDonut({ data = [] }) {
	const total = data.reduce((sum, row) => sum + (row.value || 0), 0);

	return (
		<ChartCard
			title="Tiempo desde registro"
			description="Distribución de antigüedad de clientes dormidos."
		>
			<div className="h-72 w-full">
				{total > 0 ? (
					<ResponsiveContainer width="100%" height="100%">
						<PieChart>
							<Pie
								data={data}
								dataKey="value"
								nameKey="label"
								innerRadius={58}
								outerRadius={88}
								paddingAngle={2}
							>
								{data.map((entry, index) => (
									<Cell
										key={entry.key || entry.label}
										fill={COLORS[index % COLORS.length]}
									/>
								))}
							</Pie>
							<Tooltip
								formatter={(value) => [
									`${Number(value).toLocaleString()} (${
										total
											? ((value / total) * 100).toFixed(1)
											: 0
									}%)`,
									"Clientes",
								]}
							/>
							<Legend
								verticalAlign="bottom"
								height={48}
								wrapperStyle={{ fontSize: 12 }}
							/>
						</PieChart>
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
