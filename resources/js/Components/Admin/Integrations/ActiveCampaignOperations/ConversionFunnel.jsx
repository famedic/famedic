import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

export default function ConversionFunnel({ funnel = [], updatedAt = null }) {
	const max = Math.max(...funnel.map((s) => s.count || 0), 1);

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Embudo de conversión"
				description="Registro → sincronización → calificación → compra → cliente → membresía → recompra."
				provenance={provenanceForSection("funnel")}
				updatedAt={updatedAt}
			/>
			<div className="space-y-3">
				{funnel.map((stage, index) => (
					<div key={stage.key} className="space-y-1">
						<div className="flex flex-wrap items-center justify-between gap-2 text-xs">
							<span className="font-medium text-zinc-800 dark:text-zinc-100">
								{stage.label}
							</span>
							<span className="tabular-nums text-zinc-500">
								{stage.count}
								{stage.conversion_percent != null
									? ` · conv ${stage.conversion_percent}%`
									: ""}
								{stage.dropoff_percent != null
									? ` · drop ${stage.dropoff_percent}%`
									: ""}
							</span>
						</div>
						<div className="h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
							<div
								className="h-full rounded-full bg-sky-500 transition-all duration-500"
								style={{ width: `${Math.max(4, (stage.count / max) * 100)}%` }}
							/>
						</div>
						{index < funnel.length - 1 ? (
							<div className="pl-2 text-[10px] text-zinc-400">↓</div>
						) : null}
					</div>
				))}
			</div>
		</section>
	);
}
