export default function SectionTitle({ eyebrow, title, description, action }) {
	return (
		<div className="mb-4 flex flex-wrap items-end justify-between gap-3">
			<div>
				{eyebrow ? (
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						{eyebrow}
					</p>
				) : null}
				<h2 className="mt-1 text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">
					{title}
				</h2>
				{description ? (
					<p className="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
						{description}
					</p>
				) : null}
			</div>
			{action || null}
		</div>
	);
}
