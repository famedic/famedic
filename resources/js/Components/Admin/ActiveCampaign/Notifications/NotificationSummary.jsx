import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

const TONE = {
	sky: "sky",
	red: "red",
	amber: "amber",
	zinc: "zinc",
	default: "default",
};

export default function NotificationSummary({ summary = [], meta = null }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Resumen de prioridades
				</h2>
				<p className="text-xs text-zinc-500">
					Qué requiere atención ahora — sin inventar agregaciones.
				</p>
			</div>

			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{summary.map((card) => (
					<BillingMetricCard
						key={card.id}
						label={card.label}
						value={card.value}
						hint={card.hint}
						tone={TONE[card.tone] || "default"}
					/>
				))}
			</div>

			<ChartCard
				title="Fuente de verdad"
				description={
					meta?.source_of_truth ||
					"Dashboard · Event Center · Health · Automation"
				}
				className="mt-1"
			>
				<p className="text-xs text-zinc-500">
					{meta?.note ||
						"Consolida señales existentes. No crea una fuente de eventos nueva."}
				</p>
			</ChartCard>
		</section>
	);
}
