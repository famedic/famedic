import { SectionProvenance } from "./ProvenanceHelpers";

export default function SectionHeader({
	title,
	description,
	action = null,
	provenance = null,
	updatedAt = null,
}) {
	return (
		<div className="mb-4 flex flex-wrap items-start justify-between gap-3">
			<div className="min-w-0 space-y-1">
				<div className="flex flex-wrap items-center gap-2">
					<h2 className="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
						{title}
					</h2>
					{provenance ? (
						<SectionProvenance provenance={provenance} updatedAt={updatedAt} />
					) : null}
				</div>
				{description ? (
					<p className="max-w-2xl text-xs text-zinc-500 dark:text-zinc-400">
						{description}
					</p>
				) : null}
			</div>
			{action}
		</div>
	);
}
