import DataSourceBadge from "@/Components/Common/DataSourceBadge";

export default function OwnersCard({ owners, updatedAt = null }) {
	const rows = owners?.distribution || [];
	const without = owners?.without_owner ?? 0;

	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
					Owner distribution
				</h3>
				<DataSourceBadge
					compact
					source="ACTIVECAMPAIGN_MIRROR"
					mode="CACHE"
					quality="B"
					ttl="5 min"
					endpoint="Mirror snapshot · owner"
					updatedAt={updatedAt}
				/>
			</div>
			{rows.length === 0 ? (
				<p className="mt-3 text-sm text-zinc-500">Sin datos de owners en caché.</p>
			) : (
				<ul className="mt-3 space-y-2">
					{rows.map((row) => (
						<li
							key={row.name}
							className="flex items-center justify-between gap-3 text-sm"
						>
							<span className="truncate text-zinc-700 dark:text-zinc-200">
								{row.name}
							</span>
							<span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-50">
								{row.count}
							</span>
						</li>
					))}
				</ul>
			)}
			<p className="mt-3 text-[11px] text-zinc-400">Sin owner: {without}</p>
		</div>
	);
}
