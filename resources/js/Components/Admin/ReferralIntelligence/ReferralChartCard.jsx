import {
	ResponsiveContainer,
	AreaChart,
	Area,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
} from "recharts";
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function ReferralChartCard({
	data = [],
	granularity = "day",
	onGranularityChange,
}) {
	return (
		<ChartCard
			title="Registros por día"
			description="Evolución de clientes registrados por invitación."
		>
			<div className="mb-3 flex flex-wrap gap-1.5">
				{[
					{ id: "day", label: "Día" },
					{ id: "week", label: "Semana" },
					{ id: "month", label: "Mes" },
				].map((item) => (
					<button
						key={item.id}
						type="button"
						onClick={() => onGranularityChange?.(item.id)}
						className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
							granularity === item.id
								? "bg-zinc-900 text-white dark:bg-white dark:text-zinc-900"
								: "bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300"
						}`}
					>
						{item.label}
					</button>
				))}
			</div>
			<div className={`h-64 ${CHART_UI}`}>
				<ResponsiveContainer width="100%" height="100%">
					<AreaChart data={data}>
						<defs>
							<linearGradient id="referralFill" x1="0" y1="0" x2="0" y2="1">
								<stop offset="0%" stopColor={DASHBOARD_COLORS.blue} stopOpacity={0.35} />
								<stop offset="100%" stopColor={DASHBOARD_COLORS.blue} stopOpacity={0.02} />
							</linearGradient>
						</defs>
						<CartesianGrid strokeDasharray="3 3" vertical={false} strokeOpacity={0.3} />
						<XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={24} />
						<YAxis tickLine={false} axisLine={false} width={36} allowDecimals={false} />
						<Tooltip
							contentStyle={{
								borderRadius: 12,
								border: "1px solid rgb(228 228 231)",
								fontSize: 12,
							}}
						/>
						<Area
							type="monotone"
							dataKey="value"
							stroke={DASHBOARD_COLORS.blue}
							fill="url(#referralFill)"
							strokeWidth={2}
							name="Registros"
						/>
					</AreaChart>
				</ResponsiveContainer>
			</div>
		</ChartCard>
	);
}
