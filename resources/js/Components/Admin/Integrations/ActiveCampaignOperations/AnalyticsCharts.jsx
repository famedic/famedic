import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import OpsChart from "./OpsChart";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const CHARTS = [
	{
		key: "purchases_by_day",
		title: "Compras por día",
		type: "line",
		color: "#0284c7",
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
	},
	{
		key: "contacts_synced",
		title: "Contactos sincronizados",
		type: "line",
		color: "#059669",
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
	},
	{
		key: "lead_score",
		title: "Lead Score",
		type: "doughnut",
		color: "#6366f1",
		source: "ACTIVECAMPAIGN_MIRROR",
		mode: "CACHE",
		quality: "B",
		ttl: "5 min",
	},
	{
		key: "errors",
		title: "Errores",
		type: "bar",
		color: "#e11d48",
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
	},
	{
		key: "webhooks",
		title: "Webhooks",
		type: "line",
		color: "#0d9488",
		source: "PROXY",
		mode: "ESTIMATED",
		quality: "F",
	},
	{
		key: "automations",
		title: "Automations",
		type: "bar",
		color: "#d97706",
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "B",
	},
	{
		key: "conversion",
		title: "Conversión",
		type: "line",
		color: "#7c3aed",
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "C",
	},
];

export default function AnalyticsCharts({ analytics = {}, updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Analítica"
				description="Chart.js · series del periodo filtrado."
				provenance={provenanceForSection("analytics")}
				updatedAt={updatedAt}
			/>
			<div className="grid gap-4 lg:grid-cols-2">
				{CHARTS.map((chart) => {
					const series = analytics[chart.key] || { labels: [], values: [] };
					return (
						<article
							key={chart.key}
							className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="mb-3 flex flex-wrap items-center justify-between gap-2">
								<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
									{chart.title}
								</p>
								<DataSourceBadge
									compact
									source={chart.source}
									mode={chart.mode}
									quality={chart.quality}
									ttl={chart.ttl}
									updatedAt={updatedAt}
								/>
							</div>
							<OpsChart
								type={chart.type}
								labels={series.labels}
								values={series.values}
								label={chart.title}
								color={chart.color}
								height={chart.type === "doughnut" ? 200 : 180}
							/>
						</article>
					);
				})}
			</div>
		</section>
	);
}
