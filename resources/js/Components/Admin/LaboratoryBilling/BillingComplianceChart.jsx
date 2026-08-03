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
import BillingPanel from "./BillingPanel";
import { billingChartUiClass, billingMutedTextClass } from "./billingUi";

const COLORS = {
	completed: "#65a30d",
	not_completed: "#f59e0b",
};

function ChartTooltip({ active, payload }) {
	if (!active || !payload?.length) return null;
	const item = payload[0];
	return (
		<div className="rounded-lg bg-white px-3 py-2 text-xs shadow-lg ring-1 ring-zinc-950/10 dark:bg-zinc-900 dark:ring-white/10">
			<p className="font-semibold text-zinc-900 dark:text-zinc-50">
				{item.name}
			</p>
			<p className="text-zinc-600 dark:text-zinc-300">
				<Strong>{item.value}</Strong>
			</p>
		</div>
	);
}

export default function BillingComplianceChart({ compliance }) {
	const data = [
		{
			key: "completed",
			name: "Completadas",
			value: compliance?.completed || 0,
		},
		{
			key: "not_completed",
			name: "No completadas",
			value: compliance?.not_completed || 0,
		},
	];
	const hasData = data.some((item) => item.value > 0);

	return (
		<BillingPanel aria-label="Cumplimiento de facturación">
			<div className="space-y-1">
				<Subheading>Cumplimiento de facturación</Subheading>
				<Text className="text-xs text-zinc-500 dark:text-zinc-400">
					{compliance?.definition ||
						"Cohorte por fecha de solicitud. Completada = PDF + XML con completed_at (aunque la finalización sea fuera del rango)."}
				</Text>
			</div>
			<Divider className="my-4" />
			{!hasData ? (
				<Text className={`py-10 text-center ${billingMutedTextClass}`}>
					No hay solicitudes en el rango seleccionado.
				</Text>
			) : (
				<div className="grid gap-4 md:grid-cols-[1fr_auto] md:items-center">
					<div className={`h-64 ${billingChartUiClass}`}>
						<ResponsiveContainer width="100%" height="100%">
							<PieChart>
								<Pie
									data={data}
									dataKey="value"
									nameKey="name"
									innerRadius={60}
									outerRadius={90}
									paddingAngle={2}
								>
									{data.map((entry) => (
										<Cell
											key={entry.key}
											fill={COLORS[entry.key] || "#a1a1aa"}
										/>
									))}
								</Pie>
								<Tooltip content={<ChartTooltip />} />
								<Legend />
							</PieChart>
						</ResponsiveContainer>
					</div>
					<div className="space-y-2 text-sm">
						<p className="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{compliance?.percent ?? 0}%
						</p>
						<p className="text-zinc-600 dark:text-zinc-300">
							{compliance?.completed ?? 0} completadas de{" "}
							{compliance?.received ?? 0} recibidas
						</p>
						{compliance?.target_percent == null ? (
							<p className="text-xs text-zinc-500 dark:text-zinc-400">
								Sin meta configurada en el sistema.
							</p>
						) : (
							<p className="text-xs text-zinc-500 dark:text-zinc-400">
								Meta: {compliance.target_percent}%
							</p>
						)}
					</div>
				</div>
			)}
		</BillingPanel>
	);
}
