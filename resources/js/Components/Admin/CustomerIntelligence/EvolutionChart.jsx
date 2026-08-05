import {
	ResponsiveContainer,
	AreaChart,
	Area,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import { Button } from "@/Components/Catalyst/button";
import {
	ChartCard,
	CHART_UI,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const GRANULARITIES = [
	{ value: "day", label: "Día" },
	{ value: "week", label: "Semana" },
	{ value: "month", label: "Mes" },
	{ value: "year", label: "Año" },
];

export default function EvolutionChart({ data = [], granularity = "day", onGranularityChange }) {
	return (
		<ChartCard
			title="Evolución de clientes dormidos"
			description="Registros sin compra agrupados por periodo."
		>
			<div className="mb-4 flex flex-wrap gap-2">
				{GRANULARITIES.map((item) => (
					<Button
						key={item.value}
						plain
						onClick={() => onGranularityChange?.(item.value)}
						className={
							granularity === item.value
								? "!bg-zinc-900 !text-white dark:!bg-white dark:!text-zinc-900"
								: ""
						}
					>
						{item.label}
					</Button>
				))}
			</div>
			<div className={`h-72 w-full ${CHART_UI}`}>
				{data.length ? (
					<ResponsiveContainer width="100%" height="100%">
						<AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<defs>
								<linearGradient id="dormantFill" x1="0" y1="0" x2="0" y2="1">
									<stop
										offset="0%"
										stopColor={DASHBOARD_COLORS.orange}
										stopOpacity={0.35}
									/>
									<stop
										offset="100%"
										stopColor={DASHBOARD_COLORS.orange}
										stopOpacity={0.02}
									/>
								</linearGradient>
							</defs>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} minTickGap={24} />
							<YAxis tick={{ fontSize: 11 }} width={40} allowDecimals={false} />
							<Tooltip
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Area
								type="monotone"
								dataKey="value"
								name="Dormidos"
								stroke={DASHBOARD_COLORS.orange}
								fill="url(#dormantFill)"
								strokeWidth={2}
							/>
						</AreaChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin datos en el periodo
					</div>
				)}
			</div>
		</ChartCard>
	);
}
