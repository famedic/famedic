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

function MoneyTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="mb-1 font-medium text-zinc-700 dark:text-zinc-200">{label}</p>
			{payload.map((entry) => (
				<p key={entry.dataKey} className="text-zinc-600 dark:text-zinc-300">
					{entry.name}:{" "}
					<Strong>
						$
						{Number(entry.value || 0).toLocaleString("es-MX", {
							minimumFractionDigits: 2,
							maximumFractionDigits: 2,
						})}
					</Strong>
				</p>
			))}
		</div>
	);
}

function CountTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;

	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="mb-1 font-medium text-zinc-700 dark:text-zinc-200">{label}</p>
			{payload.map((entry) => (
				<p key={entry.dataKey} className="text-zinc-600 dark:text-zinc-300">
					{entry.name}: <Strong>{entry.value}</Strong>
				</p>
			))}
		</div>
	);
}

export default function SalesVsAbandoned({ data }) {
	const daily = data?.daily || [];
	const totals = data?.totals || {};

	return (
		<div className="grid gap-4 xl:grid-cols-3">
			<ChartCard
				title="Ventas vs abandonos (monto)"
				description="Comparativo diario del valor vendido frente al valor en carritos abandonados."
				className="xl:col-span-2"
			>
				{daily.length === 0 ? (
					<Text className="text-sm text-zinc-500">Sin datos para el periodo.</Text>
				) : (
					<ResponsiveContainer height={320}>
						<BarChart data={daily} className={CHART_UI} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} className="text-xs" />
							<YAxis tickLine={false} axisLine={false} className="text-xs" />
							<Tooltip content={<MoneyTooltip />} />
							<Legend />
							<Bar
								dataKey="sold_amount"
								name="Monto vendido"
								fill={DASHBOARD_COLORS.green}
								radius={[4, 4, 0, 0]}
							/>
							<Bar
								dataKey="abandoned_amount"
								name="Monto abandonado"
								fill={DASHBOARD_COLORS.red}
								radius={[4, 4, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				)}
			</ChartCard>

			<ChartCard
				title="Carritos vendidos vs abandonados"
				description="Cantidades del periodo seleccionado."
			>
				<div className="mb-4 grid grid-cols-2 gap-3">
					<div className="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
						<p className="text-xs text-emerald-700 dark:text-emerald-300">Vendidos</p>
						<p className="mt-1 text-2xl font-semibold text-emerald-800 dark:text-emerald-200">
							{totals.sold_count ?? 0}
						</p>
					</div>
					<div className="rounded-lg bg-rose-50 p-3 dark:bg-rose-950/30">
						<p className="text-xs text-rose-700 dark:text-rose-300">Abandonados</p>
						<p className="mt-1 text-2xl font-semibold text-rose-800 dark:text-rose-200">
							{totals.abandoned_count ?? 0}
						</p>
					</div>
				</div>
				{daily.length === 0 ? (
					<Text className="text-sm text-zinc-500">Sin datos para el periodo.</Text>
				) : (
					<ResponsiveContainer height={220}>
						<BarChart data={daily} className={CHART_UI}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tickLine={false} axisLine={false} hide={daily.length > 14} />
							<YAxis allowDecimals={false} tickLine={false} axisLine={false} />
							<Tooltip content={<CountTooltip />} />
							<Bar dataKey="sold_count" name="Vendidos" fill={DASHBOARD_COLORS.green} radius={[4, 4, 0, 0]} />
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
		</div>
	);
}
