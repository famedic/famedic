import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Cell,
} from "recharts";

const CHART_UI =
	"text-zinc-600 dark:text-zinc-400 [&_.recharts-cartesian-axis-tick-value]:!fill-zinc-600 dark:[&_.recharts-cartesian-axis-tick-value]:!fill-zinc-400";

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

function SimpleTooltip({ active, payload }) {
	if (!active || !payload?.length) return null;
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="text-zinc-600 dark:text-zinc-300">
				{payload[0]?.payload?.label}: <Strong>{payload[0]?.value}</Strong>
			</p>
		</div>
	);
}

function CategoricalBarChart({ data, layout = "vertical" }) {
	if (!data?.length || data.every((d) => d.value === 0)) {
		return <Text className="text-sm text-zinc-500">Sin datos para el filtro actual.</Text>;
	}

	return (
		<ResponsiveContainer height={Math.max(220, data.length * 44)}>
			<BarChart
				data={data}
				layout={layout}
				margin={{ left: 8, right: 16, top: 8, bottom: 8 }}
				className={CHART_UI}
			>
				<CartesianGrid
					strokeDasharray="3 3"
					horizontal={layout === "vertical"}
					vertical={layout === "horizontal"}
				/>
				{layout === "vertical" ? (
					<>
						<XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} />
						<YAxis
							type="category"
							dataKey="label"
							width={140}
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
						<Cell key={entry.key} fill={entry.color || "#009ad8"} />
					))}
				</Bar>
			</BarChart>
		</ResponsiveContainer>
	);
}

export default function AccountTypeBarChart({ data }) {
	return (
		<ChartCard title="Asegurados por tipo de cuenta">
			<CategoricalBarChart data={data} />
		</ChartCard>
	);
}

export function SyncStatusBarChart({ data }) {
	return (
		<ChartCard title="Sync Murguía por estado">
			<CategoricalBarChart data={data} />
		</ChartCard>
	);
}

export function MonthlyBarChart({ signups, payments }) {
	const hasSignups = signups?.some((d) => d.value > 0);
	const hasPayments = payments?.some((d) => d.value > 0);

	return (
		<div className="grid gap-4 lg:grid-cols-2">
			<ChartCard title="Altas por mes" description="Últimos 12 meses.">
				{hasSignups ? (
					<CategoricalBarChart
						data={signups.map((d) => ({
							key: d.month,
							label: d.label,
							value: d.value,
							color: "#009ad8",
						}))}
						layout="horizontal"
					/>
				) : (
					<Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>
				)}
			</ChartCard>
			<ChartCard title="Pagos de membresía por mes" description="Últimos 12 meses.">
				{hasPayments ? (
					<CategoricalBarChart
						data={payments.map((d) => ({
							key: d.month,
							label: d.label,
							value: d.value,
							color: "#10b981",
						}))}
						layout="horizontal"
					/>
				) : (
					<Text className="text-sm text-zinc-500">Sin datos en el periodo.</Text>
				)}
			</ChartCard>
		</div>
	);
}
