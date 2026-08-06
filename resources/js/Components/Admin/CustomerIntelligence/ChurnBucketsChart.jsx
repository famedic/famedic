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

export default function ChurnBucketsChart({ data = [] }) {
	return (
		<ChartCard
			title="Churn"
			description="Clientes cuya última compra supera cada umbral."
		>
			<div className={`h-72 w-full ${CHART_UI}`}>
				{data.length ? (
					<ResponsiveContainer width="100%" height="100%">
						<BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" strokeOpacity={0.25} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis tick={{ fontSize: 11 }} width={40} allowDecimals={false} />
							<Tooltip
								contentStyle={{
									borderRadius: 12,
									border: "1px solid #e4e4e7",
									fontSize: 12,
								}}
							/>
							<Bar
								dataKey="count"
								name="Clientes"
								fill={DASHBOARD_COLORS.red}
								radius={[6, 6, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				) : (
					<div className="flex h-full items-center justify-center text-sm text-zinc-400">
						Sin datos de churn
					</div>
				)}
			</div>
			<div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
				{data.map((bucket) => (
					<div
						key={bucket.key}
						className="rounded-lg bg-rose-50 px-2 py-2 text-center dark:bg-rose-950/30"
					>
						<p className="text-[10px] uppercase text-rose-600 dark:text-rose-300">
							{bucket.label}
						</p>
						<p className="text-sm font-semibold tabular-nums text-rose-800 dark:text-rose-100">
							{Number(bucket.count || 0).toLocaleString()}
						</p>
					</div>
				))}
			</div>
		</ChartCard>
	);
}
