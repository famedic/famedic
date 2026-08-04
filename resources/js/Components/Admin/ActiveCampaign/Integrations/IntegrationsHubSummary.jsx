import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";

export default function IntegrationsHubSummary({ summary = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Resumen del hub
				</h2>
				<p className="text-xs text-zinc-500">
					Conteo honesto: disponible, no configurada o próximamente.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{summary.map((card) => (
					<BillingMetricCard
						key={card.id}
						label={card.label}
						value={card.value}
						hint={card.hint}
						tone={card.tone || "default"}
					/>
				))}
			</div>
		</section>
	);
}
