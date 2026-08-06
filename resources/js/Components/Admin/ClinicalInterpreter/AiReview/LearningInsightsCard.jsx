/**
 * Señales que AI Learning podrá aprovechar — no entrena nada.
 */
export default function LearningInsightsCard({ signals = [] }) {
	if (signals.length === 0) {
		return (
			<section className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 px-4 py-3.5 dark:border-zinc-700 dark:bg-zinc-950/40">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					Esta interpretación ayudará a mejorar el sistema
				</p>
				<p className="mt-1 text-xs text-zinc-400">
					Cuando haya correcciones o nuevas variantes, aparecerán aquí como
					señales de aprendizaje.
				</p>
			</section>
		);
	}

	return (
		<section className="rounded-xl border border-famedic-light/25 bg-famedic-light/5 px-4 py-3.5">
			<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
				Esta interpretación ayudará a mejorar el sistema
			</p>
			<p className="mt-1 text-xs text-zinc-500">Se detectó:</p>
			<p className="mt-2 text-sm font-medium text-zinc-900 dark:text-zinc-50">
				{signals.join("  +  ")}
			</p>
		</section>
	);
}
