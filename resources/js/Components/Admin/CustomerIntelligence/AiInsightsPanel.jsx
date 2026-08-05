import {
	LightBulbIcon,
	SparklesIcon,
	CheckCircleIcon,
} from "@heroicons/react/16/solid";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function AiInsightsPanel({ insights, title = "AI Marketing Insights" }) {
	if (!insights) {
		return null;
	}

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Análisis automático del embudo y recomendaciones de campaña.
				</p>
			</div>
			<div className="grid gap-4 lg:grid-cols-5">
				<ChartCard
					title={insights.headline || "Hallazgos"}
					description="Patrones detectados en el periodo filtrado."
					className="lg:col-span-3"
				>
					<ul className="space-y-3">
						{(insights.findings || []).map((finding, index) => (
							<li key={index} className="flex gap-3 text-sm text-zinc-700 dark:text-zinc-300">
								<span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300">
									<SparklesIcon className="size-3.5" />
								</span>
								<span className="leading-relaxed">{finding}</span>
							</li>
						))}
					</ul>
				</ChartCard>
				<ChartCard
					title="Recomendaciones"
					description="Acciones sugeridas para Growth y Marketing."
					className="lg:col-span-2"
				>
					<ul className="space-y-2.5">
						{(insights.recommendations || []).map((rec, index) => (
							<li
								key={index}
								className="flex gap-2.5 rounded-lg bg-zinc-50 px-3 py-2.5 text-sm text-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300"
							>
								<CheckCircleIcon className="mt-0.5 size-4 shrink-0 text-emerald-500" />
								<span>{rec}</span>
							</li>
						))}
					</ul>
					<div className="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-zinc-300 p-3 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<LightBulbIcon className="size-4 shrink-0 text-amber-500" />
						Las recomendaciones se refinarán con tracking UTM, eventos de
						viaje y sync ActiveCampaign.
					</div>
				</ChartCard>
			</div>
		</section>
	);
}
