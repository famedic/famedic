import SectionHeader from "./SectionHeader";
import LeadScoreCard from "./LeadScoreCard";
import OwnersCard from "./OwnersCard";
import ListsCard from "./ListsCard";
import { provenanceForSection } from "./provenanceCatalog";

export default function ContactIntelligenceCard({ intelligence, updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Contact Intelligence"
				description="Estadísticas globales desde snapshots del Mirror en caché (sin fichas individuales)."
				provenance={provenanceForSection("intelligence")}
				updatedAt={updatedAt}
			/>
			{intelligence?.note ? (
				<p className="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
					{intelligence.note}
				</p>
			) : null}
			<div className="grid gap-4 lg:grid-cols-3">
				<LeadScoreCard
					leadScore={intelligence?.lead_score}
					sampleSize={intelligence?.sample_size}
					updatedAt={updatedAt}
				/>
				<OwnersCard owners={intelligence?.owners} updatedAt={updatedAt} />
				<ListsCard lists={intelligence?.lists} updatedAt={updatedAt} />
			</div>
		</section>
	);
}
