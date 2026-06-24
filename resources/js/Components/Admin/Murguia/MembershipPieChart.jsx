import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import {
	ResponsiveContainer,
	PieChart,
	Pie,
	Cell,
	Tooltip,
	Legend,
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

function SimpleTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-slate-950/10 dark:bg-zinc-900 dark:ring-white/10">
			{label ? (
				<p className="font-semibold text-zinc-900 dark:text-zinc-50">{label}</p>
			) : null}
			<p className="text-zinc-600 dark:text-zinc-300">
				{payload[0]?.name ? `${payload[0].name}: ` : ""}
				<Strong>{payload[0]?.value}</Strong>
			</p>
		</div>
	);
}

export function SegmentsPieChart({ segments }) {
	if (!segments?.length || segments.every((s) => s.value === 0)) {
		return <Text className="text-sm text-zinc-500">Sin datos para el filtro actual.</Text>;
	}

	return (
		<ResponsiveContainer height={280}>
			<PieChart className={CHART_UI}>
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

export default function MembershipPieChart({ segments }) {
	return (
		<ChartCard
			title="Distribución por tipo de membresía"
			description="Según la suscripción con fecha fin más reciente."
		>
			<SegmentsPieChart segments={segments} />
		</ChartCard>
	);
}

export function LocalStatusPieChart({ segments }) {
	return (
		<ChartCard title="Activos vs vencidos vs sin suscripción">
			<SegmentsPieChart segments={segments} />
		</ChartCard>
	);
}
