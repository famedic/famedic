function Stat({ label, value }) {
	return (
		<div className="min-w-0">
			<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
				{label}
			</p>
			<p className="mt-1 text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
				{value ?? "—"}
			</p>
		</div>
	);
}

/**
 * Calidad de esta interpretación — human metrics only (no tokens).
 */
export default function InterpretationQualityCard({ quality }) {
	if (!quality) return null;

	return (
		<section className="rounded-2xl border border-zinc-200 bg-white px-5 py-5 dark:border-zinc-700 dark:bg-zinc-900">
			<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				AI Review
			</p>
			<h3 className="mt-1 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
				Calidad de esta interpretación
			</h3>

			<dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
				<Stat label="Estudios detectados" value={quality.detected} />
				<Stat
					label="Coincidencias automáticas"
					value={quality.autoMatches}
				/>
				<Stat
					label="Correcciones humanas"
					value={quality.humanCorrections}
				/>
				<Stat label="Omitidos" value={quality.omitted} />
				<Stat
					label="Tiempo de interpretación"
					value={quality.durationLabel}
				/>
				<Stat label="Modelo utilizado" value={quality.model} />
				{quality.promptVersion && (
					<Stat
						label="Versión del Prompt"
						value={
							String(quality.promptVersion).startsWith("v")
								? quality.promptVersion
								: `v${quality.promptVersion}`
						}
					/>
				)}
			</dl>
		</section>
	);
}
