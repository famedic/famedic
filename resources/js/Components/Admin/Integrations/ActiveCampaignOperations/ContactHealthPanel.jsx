import clsx from "clsx";
import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import SectionHeader from "./SectionHeader";
import { ProvenanceValue } from "./ProvenanceHelpers";
import { provenanceForSection } from "./provenanceCatalog";

const BAND_TONE = {
	emerald: "bg-emerald-500",
	sky: "bg-sky-500",
	amber: "bg-amber-500",
	rose: "bg-rose-500",
};

const INDICATOR_PROVENANCE = {
	no_email: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "A" },
	no_phone: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "A" },
	no_owner: { source: "ACTIVECAMPAIGN_MIRROR", mode: "CACHE", quality: "B", ttl: "5 min" },
	no_tags: { source: "PROXY", mode: "ESTIMATED", quality: "F" },
	no_lists: { source: "PROXY", mode: "ESTIMATED", quality: "F" },
	duplicates: { source: "FAMEDIC_DATABASE", mode: "CALCULATED", quality: "B" },
	no_purchases: { source: "FAMEDIC_DATABASE", mode: "CALCULATED", quality: "B" },
	no_activity: { source: "FAMEDIC_DATABASE", mode: "CALCULATED", quality: "C" },
};

export default function ContactHealthPanel({ contactHealth = {}, updatedAt = null }) {
	const bands = contactHealth.score_bands || [];
	const indicators = contactHealth.indicators || [];
	const total = bands.reduce((sum, b) => sum + (Number(b.count) || 0), 0) || 1;

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Contact Health"
				description={`Score visual e indicadores de calidad de datos.${
					contactHealth.sample_size ? ` Muestra: ${contactHealth.sample_size}` : ""
				}`}
				provenance={provenanceForSection("contact_health")}
				updatedAt={updatedAt}
			/>

			<div className="mb-4 flex h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
				{bands.map((band) => (
					<div
						key={band.key}
						className={clsx(BAND_TONE[band.tone] || "bg-zinc-400", "transition-all")}
						style={{ width: `${(Number(band.count) / total) * 100}%` }}
						title={`${band.label}: ${band.count}`}
					/>
				))}
			</div>

			<div className="mb-5 grid gap-3 sm:grid-cols-4">
				{bands.map((band) => (
					<div
						key={band.key}
						className="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="flex items-start justify-between gap-2">
							<p className="text-[11px] uppercase tracking-wide text-zinc-500">
								{band.label}
							</p>
							<DataSourceBadge
								compact
								source="ACTIVECAMPAIGN_MIRROR"
								mode="CACHE"
								quality="B"
								ttl="5 min"
								showMode={false}
							/>
						</div>
						<p className="mt-1 text-xl font-semibold tabular-nums">{band.count}</p>
					</div>
				))}
			</div>

			<div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
				{indicators.map((item) => {
					const provenance = INDICATOR_PROVENANCE[item.key] || {
						source: "HYBRID",
						mode: "CALCULATED",
						quality: "C",
					};
					return (
						<div
							key={item.key}
							className="rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="mb-1 flex items-center justify-between gap-2">
								<p className="text-[11px] text-zinc-500">{item.label}</p>
								<DataSourceBadge
									compact
									source={provenance.source}
									mode={provenance.mode}
									quality={provenance.quality}
									ttl={provenance.ttl}
									showMode={false}
								/>
							</div>
							<p className="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								<ProvenanceValue value={item.count} />
							</p>
						</div>
					);
				})}
			</div>
		</section>
	);
}
