import {
	ResponsiveContainer,
	AreaChart,
	Area,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ChartCard, CHART_UI, DASHBOARD_COLORS } from "./chartTheme.jsx";

function SeriesTooltip({ active, payload, label, money = false }) {
	if (!active || !payload?.length) return null;
	const value = payload[0]?.value ?? 0;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="text-zinc-600 dark:text-zinc-300">
				{label}:{" "}
				<Strong>
					{money
						? `$${Number(value).toLocaleString("es-MX", {
								minimumFractionDigits: 2,
								maximumFractionDigits: 2,
							})}`
						: value}
				</Strong>
			</p>
		</div>
	);
}

function TrendCard({ title, description, data, color, money = false }) {
	return (
		<ChartCard title={title} description={description}>
			{!data?.length ? (
				<Text className="text-sm text-zinc-500">Sin datos para el periodo.</Text>
			) : (
				<ResponsiveContainer height={240}>
					<AreaChart data={data} className={CHART_UI} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
						<CartesianGrid strokeDasharray="3 3" vertical={false} />
						<XAxis dataKey="label" tickLine={false} axisLine={false} className="text-xs" />
						<YAxis tickLine={false} axisLine={false} className="text-xs" allowDecimals={!money} />
						<Tooltip content={<SeriesTooltip money={money} />} />
						<Area
							type="monotone"
							dataKey="value"
							stroke={color}
							fill={color}
							fillOpacity={0.15}
							strokeWidth={2}
						/>
					</AreaChart>
				</ResponsiveContainer>
			)}
		</ChartCard>
	);
}

export function SalesTrendChart({ data }) {
	return (
		<TrendCard
			title="Ventas por día"
			description="Cantidad de carritos comprados."
			data={data}
			color={DASHBOARD_COLORS.green}
		/>
	);
}

export function AbandonedTrendChart({ data }) {
	return (
		<TrendCard
			title="Carritos abandonados por día"
			description="Cantidad de carritos abandonados."
			data={data}
			color={DASHBOARD_COLORS.red}
		/>
	);
}

export function RevenueTrendChart({ data }) {
	return (
		<TrendCard
			title="Monto vendido por día"
			description="Valor snapshot de carritos comprados."
			data={data}
			color={DASHBOARD_COLORS.blue}
			money
		/>
	);
}

export function AbandonedRevenueTrendChart({ data }) {
	return (
		<TrendCard
			title="Monto abandonado por día"
			description="Valor potencial perdido por día."
			data={data}
			color={DASHBOARD_COLORS.orange}
			money
		/>
	);
}
