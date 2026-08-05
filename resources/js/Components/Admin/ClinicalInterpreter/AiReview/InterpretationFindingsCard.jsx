/**
 * Hallazgos importantes detectados por la IA / matching.
 */
export default function InterpretationFindingsCard({ findings = [] }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-white px-5 py-5 dark:border-zinc-700 dark:bg-zinc-900">
			<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				Hallazgos importantes
			</p>
			<h3 className="mt-1 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
				La IA detectó
			</h3>

			{findings.length === 0 ? (
				<p className="mt-3 text-sm text-zinc-500">
					No se detectaron observaciones importantes.
				</p>
			) : (
				<ul className="mt-3 space-y-2">
					{findings.map((f) => (
						<li
							key={f.id}
							className="flex items-start gap-2.5 text-sm text-zinc-700 dark:text-zinc-200"
						>
							<span
								className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-bold text-white"
								aria-hidden
							>
								✓
							</span>
							{f.label}
						</li>
					))}
				</ul>
			)}
		</section>
	);
}
