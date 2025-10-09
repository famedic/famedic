import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	ResponsiveContainer,
	CartesianGrid,
} from "recharts";
import { Divider } from "@/Components/Catalyst/divider";

export default function CustomersChart({ chart }) {
	return (
		<div>
			<div className="flex flex-wrap justify-end gap-x-4 gap-y-2">
				<div className="flex items-center gap-1">
					<Text>{chart.averagePerDay}</Text>
					<Badge color="slate">promedio</Badge>
				</div>
				<div className="flex items-center gap-1">
					<Text>{chart.total}</Text>
					<Badge color="slate">total</Badge>
				</div>
			</div>

			<ResponsiveContainer height={300} className="mt-4 text-red-100">
				<LineChart
					data={chart.dataPoints}
					className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-dot[fill='#009ad8']]:fill-famedic-dark [&_.recharts-dot[fill='#009ad8']]:dark:fill-white [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
				>
					<CartesianGrid vertical={false} />
					<XAxis
						className="text-xs"
						tickLine={false}
						axisLine={false}
						dataKey="date"
					/>
					<YAxis
						width={60}
						tickFormatter={(value) =>
							`${Number(value).toLocaleString("es-MX")}`
						}
						className="text-xs"
						tickLine={false}
						axisLine={false}
					/>

					<Tooltip
						cursor={{
							strokeWidth: 1.5,
							strokeDasharray: "10 3",
						}}
						content={<LineChartTooltip />}
					/>
					<Line
						dot={false}
						type="monotone"
						dataKey="value"
						stroke="#009ad8"
						strokeWidth={2}
					/>
				</LineChart>
			</ResponsiveContainer>
		</div>
	);
}

function LineChartTooltip({ active, payload, label }) {
	if (active && payload && payload.length) {
		return (
			<div className="rounded-lg bg-white shadow-lg ring-1 ring-slate-950/10 dark:bg-slate-900 dark:ring-white/10">
				<div className="px-4 py-1">
					<Subheading>{label}</Subheading>
				</div>
				<Divider />
				<div className="px-4 py-1">
					<Text>{payload[0].payload.formattedValue}</Text>
				</div>
			</div>
		);
	}

	return null;
}
