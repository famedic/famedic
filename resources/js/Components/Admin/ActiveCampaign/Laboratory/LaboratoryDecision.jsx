import { Button } from "@/Components/Catalyst/button";
import AnalyticsDecisionList from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsDecisionList";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

export default function LaboratoryDecision({
	insights = [],
	recommendations = [],
	risks = [],
	suggested_actions = [],
	gaps = [],
}) {
	return (
		<section className="space-y-4">
			<div className="grid gap-3 lg:grid-cols-3">
				<AnalyticsDecisionList
					title="Insights"
					items={insights}
					empty="Sin insights."
				/>
				<AnalyticsDecisionList
					title="Riesgos"
					items={risks}
					empty="Sin riesgos."
				/>
				<AnalyticsDecisionList
					title="Recomendaciones"
					items={recommendations}
					empty="Sin recomendaciones."
				/>
			</div>

			<div className="space-y-3">
				<div>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Acciones sugeridas
					</h2>
					<p className="text-xs text-zinc-500">
						Atajos a pantallas existentes del Intelligence Platform.
					</p>
				</div>
				<div className="flex flex-wrap gap-2">
					{suggested_actions.map((action) =>
						action.enabled && action.href ? (
							<Button key={action.id} href={action.href} outline>
								{action.label}
							</Button>
						) : (
							<Button key={action.id} outline disabled>
								{action.label}
							</Button>
						),
					)}
				</div>
			</div>

			{gaps.length ? (
				<div className="rounded-xl border border-dashed border-zinc-300 bg-white/70 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
					<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
						Capacidades pendientes
					</h3>
					<ul className="mt-3 space-y-2">
						{gaps.map((gap) => (
							<li
								key={gap.label}
								className="flex flex-wrap items-start justify-between gap-2 text-sm"
							>
								<div className="min-w-0">
									<p className="font-medium text-zinc-800 dark:text-zinc-200">
										{gap.label}
									</p>
									<p className="text-xs text-zinc-500">{gap.reason}</p>
								</div>
								<AnalyticsTruthBadge truth={gap.truth} />
							</li>
						))}
					</ul>
				</div>
			) : null}
		</section>
	);
}
