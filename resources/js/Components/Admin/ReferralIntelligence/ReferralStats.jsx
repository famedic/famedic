export default function ReferralStats({ items = [] }) {
	return (
		<div className="grid grid-cols-2 gap-2">
			{items.map((item) => (
				<div
					key={item.label}
					className="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/50"
				>
					<p className="text-[10px] font-medium uppercase tracking-wide text-zinc-400">
						{item.label}
					</p>
					<p className="mt-1 text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
						{item.value}
					</p>
				</div>
			))}
		</div>
	);
}
