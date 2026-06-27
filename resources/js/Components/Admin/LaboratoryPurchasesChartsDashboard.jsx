import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import PurchasesChart from "@/Components/PurchasesChart";
import { Divider } from "@/Components/Catalyst/divider";
import {
	ResponsiveContainer,
	LineChart,
	Line,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	PieChart,
	Pie,
	Cell,
	Legend,
} from "recharts";

const CHART_UI =
	"text-zinc-600 dark:text-zinc-400 [&_.recharts-cartesian-axis-tick-value]:!fill-zinc-600 dark:[&_.recharts-cartesian-axis-tick-value]:!fill-zinc-400";

const PAYMENT_COLORS = {
	odessa: "#6366f1",
	stripe: "#009ad8",
	efevoopay: "#a855f7",
	paypal: "#0070ba",
};

function ChartCard({ title, description, children }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="space-y-1">
				<Subheading>{title}</Subheading>
				{description ? (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						{description}
					</Text>
				) : null}
			</div>
			<Divider className="my-4" />
			{children}
		</div>
	);
}

function SimpleTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			{label ? <p className="font-semibold text-zinc-900 dark:text-zinc-50">{label}</p> : null}
			<p className="text-zinc-600 dark:text-zinc-300">
				{payload[0]?.name ? `${payload[0].name}: ` : ""}
				<Strong>{payload[0]?.value}</Strong>
			</p>
		</div>
	);
}

function SummaryKpis({ summary }) {
	const items = [
		{ label: "Total pedidos", value: summary.total },
		{ label: "Activos", value: summary.active },
		{ label: "Cancelados", value: summary.cancelled },
		{ label: "Con resultados", value: summary.with_results },
		{ label: "Sin resultados", value: summary.without_results },
		{ label: "Factura pendiente", value: summary.invoice_pending },
	];

	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
			{items.map((item) => (
				<div
					key={item.label}
					className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50"
				>
					<Text className="text-xs text-zinc-500">{item.label}</Text>
					<Text className="mt-1 text-xl font-semibold tabular-nums">
						{item.value}
					</Text>
				</div>
			))}
		</div>
	);
}

function CategoricalBarChart({ data, layout = "vertical" }) {
	if (!data?.length) {
		return <Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>;
	}

	return (
		<ResponsiveContainer height={Math.max(220, data.length * 44)}>
			<BarChart
				data={data}
				layout={layout}
				margin={{ left: 8, right: 16, top: 8, bottom: 8 }}
				className={CHART_UI}
			>
				<CartesianGrid strokeDasharray="3 3" horizontal={layout === "vertical"} vertical={layout === "horizontal"} />
				{layout === "vertical" ? (
					<>
						<XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} />
						<YAxis
							type="category"
							dataKey="label"
							width={160}
							tickLine={false}
							axisLine={false}
							className="text-xs"
						/>
					</>
				) : (
					<>
						<XAxis dataKey="label" tickLine={false} axisLine={false} className="text-xs" />
						<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
					</>
				)}
				<Tooltip content={<SimpleTooltip />} />
				<Bar dataKey="value" radius={4}>
					{data.map((entry) => (
						<Cell
							key={entry.key || entry.label}
							fill={entry.color || PAYMENT_COLORS[entry.key] || "#009ad8"}
						/>
					))}
				</Bar>
			</BarChart>
		</ResponsiveContainer>
	);
}

function SegmentsPieChart({ segments }) {
	if (!segments?.length || segments.every((s) => s.value === 0)) {
		return <Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>;
	}

	return (
		<ResponsiveContainer height={280}>
			<PieChart>
				<Pie
					data={segments}
					dataKey="value"
					nameKey="label"
					cx="50%"
					cy="50%"
					innerRadius={60}
					outerRadius={100}
					paddingAngle={2}
				>
					{segments.map((entry) => (
						<Cell key={entry.key} fill={entry.color} />
					))}
				</Pie>
				<Tooltip content={<SimpleTooltip />} />
				<Legend />
			</PieChart>
		</ResponsiveContainer>
	);
}

function DailyLineChart({ dataPoints, stroke, name }) {
	if (!dataPoints?.length) {
		return <Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>;
	}

	return (
		<ResponsiveContainer height={300}>
			<LineChart data={dataPoints} className={CHART_UI}>
				<CartesianGrid vertical={false} />
				<XAxis dataKey="date" tickLine={false} axisLine={false} className="text-xs" />
				<YAxis allowDecimals={false} tickLine={false} axisLine={false} width={48} />
				<Tooltip content={<SimpleTooltip />} />
				<Line
					type="monotone"
					dataKey="value"
					name={name}
					stroke={stroke}
					strokeWidth={2}
					dot={false}
				/>
			</LineChart>
		</ResponsiveContainer>
	);
}

export default function LaboratoryPurchasesChartsDashboard({ charts }) {
	const { summary, period } = charts;

	return (
		<div className="space-y-8">
			<div className="flex flex-wrap gap-2">
				<Badge color="slate">
					{period.start_date} → {period.end_date}
				</Badge>
				<Badge color="sky">{summary.total} pedidos en el periodo</Badge>
			</div>

			<SummaryKpis summary={summary} />

			<div className="grid gap-6 xl:grid-cols-2">
				<ChartCard
					title="Pedidos por día"
					description="Cantidad de órdenes creadas cada día (incluye canceladas)."
				>
					<DailyLineChart
						dataPoints={charts.dailyOrders.dataPoints}
						stroke="#009ad8"
						name="Pedidos"
					/>
					<Text className="mt-2 text-xs text-zinc-500">
						Total en periodo: <Strong>{charts.dailyOrders.total}</Strong>
					</Text>
				</ChartCard>

				<ChartCard
					title="Ventas por día"
					description="Monto total facturado (MXN) por día."
				>
					<PurchasesChart chart={charts.dailyRevenue} />
				</ChartCard>

				<ChartCard
					title="Método de pago"
					description="Pedidos distintos por método de pago (según transacción)."
				>
					<CategoricalBarChart
						data={charts.paymentMethods.map((row) => ({
							...row,
							color: PAYMENT_COLORS[row.key] || "#009ad8",
						}))}
					/>
				</ChartCard>

				<ChartCard
					title="Resultados"
					description="PDF manual en pedido o notificación automática de resultados (GDA)."
				>
					<SegmentsPieChart segments={charts.resultsStatus} />
				</ChartCard>

				<ChartCard
					title="Factura solicitada sin subir"
					description="Entre pedidos que solicitaron factura: cuántos ya tienen archivo y cuántos siguen pendientes."
				>
					<div className="mb-4 flex flex-wrap gap-4">
						<div>
							<Text className="text-3xl font-semibold tabular-nums text-amber-600 dark:text-amber-400">
								{charts.invoicePending.count}
							</Text>
							<Text className="text-sm text-zinc-500">sin subir</Text>
						</div>
						<div>
							<Text className="text-2xl font-semibold tabular-nums">
								{charts.invoicePending.requested_total}
							</Text>
							<Text className="text-sm text-zinc-500">solicitaron factura</Text>
						</div>
					</div>
					<SegmentsPieChart segments={charts.invoicePending.segments} />
				</ChartCard>

				<ChartCard
					title="Pedidos cancelados"
					description="Órdenes con soft-delete, agrupadas por fecha de creación."
				>
					<DailyLineChart
						dataPoints={charts.cancelled.dataPoints}
						stroke="#ef4444"
						name="Cancelados"
					/>
					<Text className="mt-2 text-xs text-zinc-500">
						Total cancelados en periodo: <Strong>{charts.cancelled.total}</Strong>
					</Text>
				</ChartCard>

				<ChartCard title="Por marca" description="Pedidos activos por laboratorio.">
					<CategoricalBarChart
						data={charts.byBrand.map((row, index) => ({
							...row,
							color: ["#009ad8", "#22c55e", "#f59e0b", "#8b5cf6", "#ec4899"][index % 5],
						}))}
					/>
				</ChartCard>

				<ChartCard
					title="Notificaciones GDA"
					description="Toma de muestra y resultados automáticos vía webhook GDA (por consecutivo)."
				>
					<CategoricalBarChart data={charts.notifications} />
				</ChartCard>
			</div>
		</div>
	);
}
