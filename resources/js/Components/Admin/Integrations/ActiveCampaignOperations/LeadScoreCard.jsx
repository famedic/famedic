import MetricCard from "./MetricCard";
import DataSourceBadge from "@/Components/Common/DataSourceBadge";

const MIRROR = {
	source: "ACTIVECAMPAIGN_MIRROR",
	mode: "CACHE",
	quality: "B",
	ttl: "5 min",
	endpoint: "GET /contacts/{id}/scoreValues (via Mirror)",
};

export default function LeadScoreCard({ leadScore, sampleSize = 0, updatedAt = null }) {
	const labels = leadScore?.labels || {};

	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
					Lead Score
				</h3>
				<DataSourceBadge
					compact
					source="ACTIVECAMPAIGN_MIRROR"
					mode="CACHE"
					quality="B"
					ttl="5 min"
					endpoint="Mirror snapshot · leadScoreSummary"
					updatedAt={updatedAt}
				/>
			</div>
			<div className="mt-3 grid gap-2 sm:grid-cols-2">
				<MetricCard
					label={labels.excellent || "Excelente"}
					value={leadScore?.excellent ?? 0}
					tone="emerald"
					provenance={MIRROR}
				/>
				<MetricCard
					label={labels.good || "Bueno"}
					value={leadScore?.good ?? 0}
					tone="sky"
					provenance={MIRROR}
				/>
				<MetricCard
					label={labels.risk || "En Riesgo"}
					value={leadScore?.risk ?? 0}
					tone="amber"
					provenance={MIRROR}
				/>
				<MetricCard
					label={labels.critical || "Crítico"}
					value={leadScore?.critical ?? 0}
					tone="rose"
					provenance={MIRROR}
				/>
			</div>
			<p className="mt-2 text-[11px] text-zinc-400">
				Muestra: {sampleSize} snapshots en caché
			</p>
		</div>
	);
}
