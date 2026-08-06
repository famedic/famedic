/**
 * Historial de decisiones — handoff AI → Checkout.
 */
export default function DecisionHistoryTimeline({ steps = [] }) {
	if (!steps.length) return null;

	const formatWhen = (iso) => {
		if (!iso) return null;
		try {
			return new Date(iso).toLocaleString("es-MX", {
				dateStyle: "medium",
				timeStyle: "short",
			});
		} catch {
			return null;
		}
	};

	return (
		<section className="space-y-3">
			<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				Historial de decisiones
			</p>
			<ol className="space-y-0">
				{steps.map((step, index) => {
					const when = formatWhen(step.at);
					const isLast = index === steps.length - 1;
					return (
						<li key={step.id} className="relative flex gap-3 pb-5 last:pb-0">
							{!isLast && (
								<span
									aria-hidden
									className="absolute bottom-0 left-[11px] top-7 w-px bg-zinc-200 dark:bg-zinc-700"
								/>
							)}
							<span
								className={`relative z-10 mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ${
									step.done
										? "bg-emerald-600 text-white"
										: "border border-zinc-300 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900"
								}`}
							>
								{step.done ? "✓" : "○"}
							</span>
							<div className="min-w-0 pt-0.5">
								<p
									className={`text-sm font-medium ${
										step.done
											? "text-zinc-900 dark:text-zinc-50"
											: "text-zinc-400"
									}`}
								>
									{step.label}
								</p>
								<p className="text-xs text-zinc-400">
									{[step.actor, when].filter(Boolean).join(" · ") ||
										"Pendiente"}
								</p>
							</div>
						</li>
					);
				})}
			</ol>
		</section>
	);
}
