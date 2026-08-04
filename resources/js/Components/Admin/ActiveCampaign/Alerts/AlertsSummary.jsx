import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const TONE = {
	sky: "sky",
	red: "red",
	amber: "amber",
	zinc: "zinc",
	default: "default",
};

export default function AlertsSummary({ summary = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Dashboard superior
				</h2>
				<p className="text-xs text-zinc-500">
					Prioridades abiertas y resolución — sin inventar agregaciones.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
				{summary.map((card) => (
					<div key={card.id} className="relative">
						<div className="absolute right-3 top-3 z-10">
							<AnalyticsTruthBadge truth={card.truth || "disponible"} />
						</div>
						<BillingMetricCard
							label={card.label}
							value={card.value}
							hint={card.hint}
							tone={TONE[card.tone] || "default"}
							className="pr-24"
						/>
					</div>
				))}
			</div>
		</section>
	);
}
