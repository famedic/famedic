import MetricCard from "./MetricCard";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const DB = {
	source: "FAMEDIC_DATABASE",
	mode: "LOCAL",
	quality: "A",
	endpoint: "activecampaign_dispatches",
};

export default function SyncCard({ sync, updatedAt = null }) {
	const categories = sync?.categories || {};

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Sync"
				description="Métricas del día desde activecampaign_dispatches."
				provenance={provenanceForSection("sync")}
				updatedAt={updatedAt}
			/>
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<MetricCard label="Sincronizados" value={sync?.synced} tone="emerald" provenance={DB} updatedAt={updatedAt} />
				<MetricCard label="Errores" value={sync?.failed} tone="rose" provenance={DB} updatedAt={updatedAt} />
				<MetricCard label="Pendientes" value={sync?.pending} tone="amber" provenance={DB} updatedAt={updatedAt} />
				<MetricCard label="Reintentos" value={sync?.retries} provenance={DB} updatedAt={updatedAt} />
				<MetricCard label="Registros" value={categories.registros} provenance={DB} />
				<MetricCard label="Compras" value={categories.compras} provenance={DB} />
				<MetricCard label="Pedidos" value={categories.pedidos} provenance={DB} />
				<MetricCard label="Resultados" value={categories.resultados} provenance={DB} />
				<MetricCard label="Membresías" value={categories.membresias} provenance={DB} />
				<MetricCard label="Cupones" value={categories.cupones} provenance={DB} />
				<MetricCard label="Total hoy" value={sync?.total} tone="sky" provenance={DB} />
			</div>
		</section>
	);
}
