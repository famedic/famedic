import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "./chartTheme.jsx";

function formatMoney(value) {
	return `$${Number(value || 0).toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})}`;
}

export default function RevenueDistribution({ data }) {
	const buckets = data?.buckets || [];

	return (
		<div className="grid gap-4 xl:grid-cols-3">
			<ChartCard
				title="Distribución de carritos por monto"
				description="Histograma de tickets vendidos vs abandonados."
				className="xl:col-span-2"
			>
				{buckets.length === 0 ? (
					<Text className="text-sm text-zinc-500">Sin datos.</Text>
				) : (
					<ResponsiveContainer height={300}>
						<BarChart data={buckets} className={CHART_UI}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} className="text-xs" />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip />
							<Legend />
							<Bar
								dataKey="sold_count"
								name="Vendidos"
								fill={DASHBOARD_COLORS.green}
								radius={[4, 4, 0, 0]}
							/>
							<Bar
								dataKey="abandoned_count"
								name="Abandonados"
								fill={DASHBOARD_COLORS.red}
								radius={[4, 4, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>

			<ChartCard
				title="Ticket promedio"
				description="Comparativo vendido vs abandonado."
			>
				<div className="space-y-4">
					<div className="rounded-lg bg-emerald-50 p-4 dark:bg-emerald-950/30">
						<p className="text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
							Ticket promedio vendido
						</p>
						<p className="mt-2 text-2xl font-semibold text-emerald-900 dark:text-emerald-100">
							{data?.avg_ticket_sold == null
								? "—"
								: formatMoney(data.avg_ticket_sold)}
						</p>
					</div>
					<div className="rounded-lg bg-rose-50 p-4 dark:bg-rose-950/30">
						<p className="text-xs font-medium uppercase tracking-wide text-rose-700 dark:text-rose-300">
							Ticket promedio abandonado
						</p>
						<p className="mt-2 text-2xl font-semibold text-rose-900 dark:text-rose-100">
							{data?.avg_ticket_abandoned == null
								? "—"
								: formatMoney(data.avg_ticket_abandoned)}
						</p>
					</div>
					{data?.avg_ticket_sold != null &&
					data?.avg_ticket_abandoned != null ? (
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Diferencia:{" "}
							<Strong>
								{formatMoney(
									Math.abs(
										data.avg_ticket_sold - data.avg_ticket_abandoned,
									),
								)}
							</Strong>
						</Text>
					) : null}
				</div>
			</ChartCard>
		</div>
	);
}
