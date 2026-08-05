/**
 * Compact validation summary.
 */
export default function ValidateSummaryPanel({
	total = 0,
	confirmed = 0,
	corrected = 0,
	omitted = 0,
	deferred = 0,
	pending = 0,
}) {
	const rows = [
		{ label: "Total de estudios", value: total },
		{ label: "Confirmados", value: confirmed },
		{ label: "Corregidos", value: corrected },
		{ label: "Omitidos", value: omitted },
		{ label: "Pendiente de resolución", value: deferred },
		{ label: "Sin decidir", value: pending },
	];

	return (
		<aside className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4 dark:border-zinc-700 dark:bg-zinc-950/40 lg:sticky lg:top-4">
			<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				Resumen
			</p>
			<ul className="mt-3 space-y-3">
				{rows.map((row) => (
					<li
						key={row.label}
						className="flex items-baseline justify-between gap-3"
					>
						<span className="text-xs text-zinc-500">{row.label}</span>
						<span className="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{row.value}
						</span>
					</li>
				))}
			</ul>
		</aside>
	);
}
