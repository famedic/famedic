import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import {
	ResponsiveContainer,
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";
import BillingPanel from "./BillingPanel";
import {
	billingChartGridClass,
	billingChartUiClass,
	billingMutedTextClass,
} from "./billingUi";

function ChartTooltip({ active, payload, label }) {
	if (!active || !payload?.length) return null;
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-zinc-950/10 dark:bg-zinc-900 dark:ring-white/10">
			{label ? (
				<p className="font-semibold text-zinc-900 dark:text-zinc-50">{label}</p>
			) : null}
			{payload.map((entry) => (
				<p key={entry.dataKey} className="text-zinc-600 dark:text-zinc-300">
					{entry.name}: <Strong>{entry.value}</Strong>
				</p>
			))}
		</div>
	);
}

export default function BillingTrendChart({
	title,
	description,
	points = [],
	series = [
		{ key: "requests", name: "Solicitudes", color: "#0ea5e9" },
		{ key: "invoices_completed", name: "Facturas completas", color: "#65a30d" },
	],
}) {
	const hasData = points.some((point) =>
		series.some((item) => Number(point[item.key] || 0) > 0),
	);

	return (
		<BillingPanel aria-label={title}>
			<div className="space-y-1">
				<Subheading>{title}</Subheading>
				{description ? (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						{description}
					</Text>
				) : null}
			</div>
			<Divider className="my-4" />
			{!hasData ? (
				<Text className={`py-10 text-center ${billingMutedTextClass}`}>
					No hay datos para el rango seleccionado.
				</Text>
			) : (
				<div className={`h-72 w-full ${billingChartUiClass} ${billingChartGridClass}`}>
					<ResponsiveContainer width="100%" height="100%">
						<LineChart data={points}>
							<CartesianGrid strokeDasharray="3 3" vertical={false} />
							<XAxis dataKey="label" tick={{ fontSize: 11 }} />
							<YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
							<Tooltip content={<ChartTooltip />} />
							<Legend />
							{series.map((item) => (
								<Line
									key={item.key}
									type="monotone"
									dataKey={item.key}
									name={item.name}
									stroke={item.color}
									strokeWidth={2}
									dot={false}
								/>
							))}
						</LineChart>
					</ResponsiveContainer>
				</div>
			)}
		</BillingPanel>
	);
}
