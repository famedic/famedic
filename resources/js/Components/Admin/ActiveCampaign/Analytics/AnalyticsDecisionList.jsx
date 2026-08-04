import AnalyticsTruthBadge from "./AnalyticsTruthBadge";

export default function AnalyticsDecisionList({ title, items = [], empty }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
				{title}
			</h3>
			{!items.length ? (
				<p className="mt-3 text-sm text-zinc-500">{empty}</p>
			) : (
				<ul className="mt-3 space-y-2.5">
					{items.map((item, index) => (
						<li
							key={`${title}-${index}`}
							className="flex items-start justify-between gap-3 text-sm text-zinc-700 dark:text-zinc-300"
						>
							<span className="min-w-0 flex-1 leading-snug">{item.text || item.label}</span>
							<AnalyticsTruthBadge truth={item.truth} />
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
