export default function ReferralTimeline({ items = [] }) {
	return (
		<ol className="relative space-y-4 border-l border-zinc-200 pl-4 dark:border-zinc-700">
			{items.map((item) => (
				<li key={item.key || item.label} className="relative">
					<span className="absolute -left-[21px] top-1 size-2.5 rounded-full bg-zinc-400 ring-4 ring-white dark:ring-zinc-900" />
					<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
						{item.label}
					</p>
					<p className="text-xs text-zinc-500">{item.at || "—"}</p>
				</li>
			))}
		</ol>
	);
}
