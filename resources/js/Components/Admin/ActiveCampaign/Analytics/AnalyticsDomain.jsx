import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import AnalyticsTruthBadge from "./AnalyticsTruthBadge";
import AnalyticsDecisionList from "./AnalyticsDecisionList";
import AnalyticsCharts from "./AnalyticsCharts";

const TONE_MAP = {
	green: "lime",
	amber: "amber",
	red: "red",
	sky: "sky",
	default: "default",
};

function isBusinessKpi(kpi) {
	return kpi && Object.prototype.hasOwnProperty.call(kpi, "value_formatted");
}

export default function AnalyticsDomain({ domain, charts }) {
	const healthCards = (domain.kpis || []).filter((k) => !isBusinessKpi(k));
	const businessKpis = (domain.kpis || []).filter(isBusinessKpi);

	return (
		<section
			id={`domain-${domain.id}`}
			className="space-y-4 rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-950/40 sm:p-5"
		>
			<header className="space-y-1">
				<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
					Dominio
				</p>
				<h2 className="font-poppins text-lg font-semibold tracking-tight text-zinc-950 dark:text-white">
					{domain.label}
				</h2>
				<p className="text-sm text-zinc-600 dark:text-zinc-400">{domain.question}</p>
			</header>

			{/* KPIs */}
			<div className="space-y-3">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
					KPIs
				</h3>
				{healthCards.length ? (
					<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
						{healthCards.map((card) => (
							<div key={card.id} className="relative">
								<div className="absolute right-3 top-3 z-10">
									<AnalyticsTruthBadge truth={card.truth} />
								</div>
								<BillingMetricCard
									label={card.label}
									value={card.value}
									hint={card.hint}
									tone={TONE_MAP[card.tone] || "default"}
									className="pr-24"
								/>
							</div>
						))}
					</div>
				) : null}
				{businessKpis.length ? (
					<>
						<div className="flex flex-wrap gap-2">
							{businessKpis.map((kpi) => (
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
							kpis={businessKpis}
							columnsClassName="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
						/>
					</>
				) : null}
				{!healthCards.length && !businessKpis.length ? (
					<p className="text-sm text-zinc-500">Sin KPIs reutilizables en este dominio.</p>
				) : null}
			</div>

			{/* Gráficas */}
			<div className="space-y-3">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
					Gráficas
				</h3>
				<AnalyticsCharts chartKeys={domain.chart_keys} charts={charts} />
			</div>

			{/* Decisión */}
			<div className="grid gap-3 lg:grid-cols-3">
				<AnalyticsDecisionList
					title="Insights"
					items={domain.insights}
					empty="Sin insights."
				/>
				<AnalyticsDecisionList
					title="Recomendaciones"
					items={domain.recommendations}
					empty="Sin recomendaciones."
				/>
				<AnalyticsDecisionList
					title="Riesgos"
					items={domain.risks}
					empty="Sin riesgos."
				/>
			</div>

			{domain.gaps?.length ? (
				<div className="rounded-xl border border-dashed border-zinc-300 bg-white/70 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
					<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
						Capacidades pendientes
					</h3>
					<ul className="mt-3 space-y-2">
						{domain.gaps.map((gap) => (
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
