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
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "./chartTheme.jsx";

function formatMoney(value) {
	return `$${Number(value || 0).toLocaleString("es-MX", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})}`;
}

function HorizontalBars({
	title,
	description,
	data,
	color,
	money = false,
	percent = false,
}) {
	const rows = (data || []).filter(
		(row) => row.value !== null && row.value !== undefined,
	);

	return (
		<ChartCard title={title} description={description}>
			{rows.length === 0 ? (
				<Text className="text-sm text-zinc-500">Sin datos.</Text>
			) : (
				<ResponsiveContainer height={Math.max(220, rows.length * 44)}>
					<BarChart
						data={rows}
						layout="vertical"
						className={CHART_UI}
						margin={{ left: 8, right: 16, top: 8, bottom: 8 }}
					>
						<CartesianGrid strokeDasharray="3 3" horizontal={false} />
						<XAxis type="number" tickLine={false} axisLine={false} />
						<YAxis
							type="category"
							dataKey="label"
							width={100}
							tickLine={false}
							axisLine={false}
							className="text-xs"
						/>
						<Tooltip
							formatter={(value) => {
								if (money) return formatMoney(value);
								if (percent) return `${value ?? "—"}%`;
								return value;
							}}
						/>
						<Bar dataKey="value" fill={color} radius={[0, 4, 4, 0]} />
					</BarChart>
				</ResponsiveContainer>
			)}
		</ChartCard>
	);
}

export function SalesByLaboratory({ data }) {
	return (
		<HorizontalBars
			title="Ventas por laboratorio"
			description="Carritos comprados, de mayor a menor."
			data={data}
			color={DASHBOARD_COLORS.green}
		/>
	);
}

export function AbandonedByLaboratory({ data }) {
	return (
		<HorizontalBars
			title="Abandonos por laboratorio"
			description="Carritos abandonados, de mayor a menor."
			data={data}
			color={DASHBOARD_COLORS.red}
		/>
	);
}

export function ConversionByLaboratory({ data }) {
	return (
		<HorizontalBars
			title="Conversión por laboratorio"
			description="Porcentaje de compra sobre (comprados + abandonados)."
			data={data}
			color={DASHBOARD_COLORS.purple}
			percent
		/>
	);
}

export function RevenueByLaboratory({ data }) {
	return (
		<HorizontalBars
			title="Valor vendido por laboratorio"
			description="Ingreso de pedidos en el periodo."
			data={data}
			color={DASHBOARD_COLORS.blue}
			money
		/>
	);
}

export function LostValueByLaboratory({ data }) {
	return (
		<HorizontalBars
			title="Valor perdido por laboratorio"
			description="Monto snapshot de carritos abandonados."
			data={data}
			color={DASHBOARD_COLORS.orange}
			money
		/>
	);
}

export default function LaboratoryBreakdown({ charts }) {
	return (
		<div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
			<div className="xl:col-span-1">
				<SalesByLaboratory data={charts?.sales} />
			</div>
			<div className="xl:col-span-1">
				<AbandonedByLaboratory data={charts?.abandoned} />
			</div>
			<div className="xl:col-span-1">
				<ConversionByLaboratory data={charts?.conversion} />
			</div>
			<div className="lg:col-span-1 xl:col-span-1">
				<RevenueByLaboratory data={charts?.revenue} />
			</div>
			<div className="lg:col-span-1 xl:col-span-2">
				<LostValueByLaboratory data={charts?.lost_value} />
			</div>
			{(charts?.conversion || []).length > 0 ? (
				<div className="lg:col-span-2 xl:col-span-3">
					<div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900/40">
						<p className="font-medium text-zinc-900 dark:text-zinc-50">
							Lectura rápida
						</p>
						<p className="mt-1 text-zinc-600 dark:text-zinc-300">
							Mejor conversión:{" "}
							<Strong>
								{[...(charts.conversion || [])].sort(
									(a, b) => (b.value || 0) - (a.value || 0),
								)[0]?.label || "—"}
							</Strong>
							{" · "}
							Mayor pérdida:{" "}
							<Strong>
								{[...(charts.lost_value || [])].sort(
									(a, b) => (b.value || 0) - (a.value || 0),
								)[0]?.label || "—"}
							</Strong>
						</p>
					</div>
				</div>
			) : null}
		</div>
	);
}
