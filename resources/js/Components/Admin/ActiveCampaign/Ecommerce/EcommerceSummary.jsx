import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";

const TONE = {
	sky: "sky",
	lime: "lime",
	amber: "amber",
	red: "red",
	default: "default",
};

export default function EcommerceSummary({ summary = [], kpis = [] }) {
	return (
		<section className="space-y-4">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Dashboard ejecutivo
				</h2>
				<p className="text-xs text-zinc-500">
					GMV consolidado Lab + Farmacia + Membresías para Dirección.
				</p>
			</div>

			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{summary.map((card) => (
					<div key={card.id} className="relative">
						<div className="absolute right-3 top-3 z-10">
							<AnalyticsTruthBadge truth={card.truth} />
						</div>
						<BillingMetricCard
							label={card.label}
							value={card.value}
							hint={
								card.delta
									? `${card.hint || ""} · vs ant. ${card.delta}`.trim()
									: card.hint
							}
							tone={TONE[card.tone] || "default"}
							className="pr-24"
						/>
					</div>
				))}
			</div>

			{kpis.length ? (
				<div className="space-y-2">
					<div className="flex flex-wrap gap-2">
						{kpis.map((kpi) => (
							<div
								key={kpi.id}
								className="inline-flex items-center gap-1.5 text-xs text-zinc-500"
							>
								<span className="font-medium text-zinc-700 dark:text-zinc-300">
									{kpi.label}
								</span>
								<AnalyticsTruthBadge truth={kpi.truth} />
							</div>
						))}
					</div>
					<KpiCards
						kpis={kpis}
						columnsClassName="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
					/>
				</div>
			) : null}
		</section>
	);
}
