import MetricCard from "./MetricCard";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const MIRROR = {
	source: "ACTIVECAMPAIGN_MIRROR",
	mode: "CACHE",
	quality: "B",
	ttl: "5 min",
	endpoint: "ActiveCampaignCacheService",
};

const DB = {
	source: "FAMEDIC_DATABASE",
	mode: "LOCAL",
	quality: "A",
	endpoint: "customers.ac_*",
};

export default function MirrorCard({ mirror, updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Mirror"
				description="Caché de lectura ActiveCampaignMirrorService (TTL 5 min)."
				provenance={provenanceForSection("mirror")}
				updatedAt={updatedAt || mirror?.last_sync_at}
			/>
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<MetricCard label="Snapshots en caché" value={mirror?.snapshots_cached} tone="sky" provenance={MIRROR} updatedAt={updatedAt} />
				<MetricCard label="Cache hit" value={mirror?.cache_hits} tone="emerald" provenance={MIRROR} />
				<MetricCard label="Cache miss" value={mirror?.cache_misses} tone="amber" provenance={MIRROR} />
				<MetricCard label="TTL" value={mirror?.ttl_human} provenance={MIRROR} />
				<MetricCard label="Customers vinculados" value={mirror?.customers_linked} provenance={DB} />
				<MetricCard label="Syncs hoy" value={mirror?.synced_today} provenance={DB} />
				<MetricCard label="Última sincronización" value={mirror?.last_sync_at} provenance={DB} />
				<MetricCard label="Última invalidación" value={mirror?.last_invalidation_at} provenance={MIRROR} />
			</div>
			{mirror?.sample_size != null ? (
				<p className="mt-3 text-[11px] text-zinc-400">
					Hit/miss calculado sobre {mirror.sample_size} customers con ac_contact_id
					(muestra reciente).
				</p>
			) : null}
		</section>
	);
}
