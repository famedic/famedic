import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

export default function JourneySummary({ summary }) {
	if (!summary) {
		return (
			<ChartCard title="Resumen del Journey">
				<Text className="text-sm text-zinc-500">
					Selecciona un paciente para visualizar su recorrido.
				</Text>
			</ChartCard>
		);
	}

	const items = [
		{ label: "Paciente", value: summary.patient },
		{ label: "Periodo", value: summary.period },
		{ label: "Estado actual", value: summary.status },
		{ label: "Eventos", value: String(summary.events_count) },
		{ label: "Última actividad", value: summary.last_activity },
	];

	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
			{items.map((item) => (
				<div
					key={item.label}
					className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
						{item.label}
					</p>
					<p className="mt-1 truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						{item.value}
					</p>
					{item.label === "Paciente" ? (
						<Badge color="sky" className="mt-2">
							#{summary.contact_id}
						</Badge>
					) : null}
				</div>
			))}
		</div>
	);
}
