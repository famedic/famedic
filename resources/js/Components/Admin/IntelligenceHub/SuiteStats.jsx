export default function SuiteStats({ stats = [], className = "" }) {
	return (
		<div className={`grid grid-cols-2 gap-2 sm:grid-cols-4 ${className}`}>
			{stats.map((stat) => (
				<div
					key={stat.label}
					className="rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/40"
				>
					<p className="text-[10px] font-medium uppercase tracking-wide text-zinc-400">
						{stat.label}
					</p>
					<p className="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
						{stat.value}
					</p>
				</div>
			))}
		</div>
	);
}
