import MetricCard from "./MetricCard";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const DB = {
	source: "FAMEDIC_DATABASE",
	mode: "LOCAL",
	quality: "B",
	endpoint: "medical_attention_subscriptions",
};

const PROXY = {
	source: "PROXY",
	mode: "ESTIMATED",
	quality: "D",
	endpoint: "proxy / sin flag de renovación",
};

const INSTR = {
	source: "PROXY",
	mode: "ESTIMATED",
	quality: "F",
	endpoint: "requiere instrumentación",
};

export default function MembershipsDashboard({ memberships = {}, updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Dashboard de membresías"
				description="Stock y altas del periodo (Membership Intelligence)."
				provenance={provenanceForSection("memberships")}
				updatedAt={updatedAt}
			/>
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<MetricCard label="Activas" value={memberships.active} tone="emerald" provenance={DB} updatedAt={updatedAt} />
				<MetricCard label="Pendientes / nuevas" value={memberships.pending} tone="sky" provenance={DB} />
				<MetricCard label="Canceladas" value={memberships.cancelled} tone="rose" provenance={PROXY} />
				<MetricCard label="Vencidas" value={memberships.expired} tone="amber" provenance={PROXY} />
				<MetricCard label="Renovadas" value={memberships.renewed} provenance={INSTR} />
				<MetricCard label="Renovación %" value={memberships.renewal_rate} provenance={PROXY} />
				<MetricCard label="Cancelación %" value={memberships.cancel_rate} provenance={PROXY} />
			</div>
		</section>
	);
}
