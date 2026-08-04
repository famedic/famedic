import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "./chartTheme.jsx";

function formatMoney(value) {
	return `$${Number(value || 0).toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})}`;
}

function LabTooltip({ active, payload }) {
	if (!active || !payload?.length) return null;
	const row = payload[0]?.payload;
	if (!row) return null;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="mb-1 font-medium">{row.brand_label}</p>
			<p>Ingreso: <Strong>{formatMoney(row.revenue)}</Strong></p>
			<p>Perdido: <Strong>{formatMoney(row.abandoned_value)}</Strong></p>
			<p>Conversión: <Strong>{row.conversion_percent ?? "—"}%</Strong></p>
		</div>
	);
}

export default function TopLaboratories({ laboratories = [] }) {
	const chartData = [...laboratories].sort(
		(a, b) => (b.revenue || 0) - (a.revenue || 0),
	);

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			<ChartCard
				title="Ingreso por laboratorio"
				description="Revenue de pedidos en el periodo (ordenado de mayor a menor)."
			>
				{chartData.length === 0 ? (
					<Text className="text-sm text-zinc-500">Sin datos de laboratorios.</Text>
				) : (
					<ResponsiveContainer height={Math.max(240, chartData.length * 48)}>
						<BarChart
							data={chartData}
							layout="vertical"
							className={CHART_UI}
							margin={{ left: 8, right: 16, top: 8, bottom: 8 }}
						>
							<CartesianGrid strokeDasharray="3 3" horizontal={false} />
							<XAxis type="number" tickLine={false} axisLine={false} />
							<YAxis
								type="category"
								dataKey="brand_label"
								width={100}
								tickLine={false}
								axisLine={false}
								className="text-xs"
							/>
							<Tooltip content={<LabTooltip />} />
							<Bar dataKey="revenue" name="Ingreso" fill={DASHBOARD_COLORS.blue} radius={[0, 4, 4, 0]} />
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>

			<ChartCard
				title="Ranking de laboratorios"
				description="Ventas, abandonos, conversión e ingreso."
			>
				{laboratories.length === 0 ? (
					<Text className="text-sm text-zinc-500">Sin datos de laboratorios.</Text>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full text-left text-sm">
							<thead className="text-xs uppercase tracking-wide text-zinc-500">
								<tr>
									<th className="pb-2 pr-3 font-medium">Laboratorio</th>
									<th className="pb-2 pr-3 font-medium">Ventas</th>
									<th className="pb-2 pr-3 font-medium">Abandonados</th>
									<th className="pb-2 pr-3 font-medium">Conversión</th>
									<th className="pb-2 pr-3 font-medium">Ingreso</th>
									<th className="pb-2 font-medium">Perdido</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
								{laboratories.map((row) => (
									<tr key={row.brand}>
										<td className="py-2.5 pr-3 font-medium text-zinc-900 dark:text-zinc-50">
											{row.brand_label}
										</td>
										<td className="py-2.5 pr-3 tabular-nums">{row.sales_count}</td>
										<td className="py-2.5 pr-3 tabular-nums text-rose-600 dark:text-rose-400">
											{row.abandoned_count}
										</td>
										<td className="py-2.5 pr-3">
											<Badge color="violet">
												{row.conversion_percent === null
													? "—"
													: `${row.conversion_percent}%`}
											</Badge>
										</td>
										<td className="py-2.5 pr-3 tabular-nums text-emerald-700 dark:text-emerald-400">
											{formatMoney(row.revenue)}
										</td>
										<td className="py-2.5 tabular-nums text-rose-700 dark:text-rose-400">
											{formatMoney(row.abandoned_value)}
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}
			</ChartCard>
		</div>
	);
}
