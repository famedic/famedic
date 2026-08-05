import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from "recharts";
import { ChartCard, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const COLORS = [
	DASHBOARD_COLORS.slate,
	DASHBOARD_COLORS.blue,
	DASHBOARD_COLORS.green,
	DASHBOARD_COLORS.purple,
	DASHBOARD_COLORS.orange,
];

export default function ReferralStatusDonut({ data = [] }) {
	const total = data.reduce((sum, row) => sum + (row.value || 0), 0);

	return (
		<ChartCard
			title="Estado de referidos"
			description="Nuevo · Verificado · Compró · Membresía · Inactivo"
		>
			<div className="grid gap-4 sm:grid-cols-2 sm:items-center">
				<div className="h-52">
					<ResponsiveContainer width="100%" height="100%">
						<PieChart>
							<Pie
								data={data}
								dataKey="value"
								nameKey="label"
								innerRadius={55}
								outerRadius={80}
								paddingAngle={2}
							>
								{data.map((entry, index) => (
									<Cell
										key={entry.key || entry.label}
										fill={COLORS[index % COLORS.length]}
									/>
								))}
							</Pie>
							<Tooltip />
						</PieChart>
					</ResponsiveContainer>
				</div>
				<ul className="space-y-2">
					{data.map((row, index) => {
						const pct = total > 0 ? Math.round((row.value / total) * 100) : 0;
						return (
							<li
								key={row.key || row.label}
								className="flex items-center justify-between gap-3 text-sm"
							>
								<span className="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
									<span
										className="size-2.5 rounded-full"
										style={{ background: COLORS[index % COLORS.length] }}
									/>
									{row.label}
								</span>
								<span className="tabular-nums text-zinc-900 dark:text-zinc-100">
									{row.value} · {pct}%
								</span>
							</li>
						);
					})}
				</ul>
			</div>
		</ChartCard>
	);
}
