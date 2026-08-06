import {
	LightBulbIcon,
	SparklesIcon,
	CheckCircleIcon,
} from "@heroicons/react/16/solid";

export default function ExecutiveSummary({ summary }) {
	if (!summary) {
		return null;
	}

	return (
		<section className="rounded-3xl border border-violet-200/60 bg-gradient-to-br from-violet-50/80 via-white to-indigo-50/40 p-6 dark:border-violet-500/20 dark:from-violet-950/30 dark:via-zinc-900 dark:to-indigo-950/20">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-violet-500">
						{summary.headline || "AI Executive Summary"}
					</p>
					<p className="mt-2 text-lg font-semibold text-zinc-900 dark:text-white">
						{summary.greeting}
					</p>
					<p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
						{summary.intro}
					</p>
				</div>
				<span className="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-white/80 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200">
					<SparklesIcon className="size-3.5" />
					IA
				</span>
			</div>

			<div className="mt-5 grid gap-4 lg:grid-cols-5">
				<ul className="space-y-2.5 lg:col-span-3">
					{(summary.findings || []).map((finding, index) => (
						<li
							key={index}
							className="flex gap-3 text-sm text-zinc-700 dark:text-zinc-300"
						>
							<span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-600 dark:bg-violet-500/20 dark:text-violet-300">
								•
							</span>
							<span className="leading-relaxed">{finding}</span>
						</li>
					))}
				</ul>
				<div className="rounded-2xl border border-zinc-200/80 bg-white/70 p-4 dark:border-zinc-700 dark:bg-zinc-900/50 lg:col-span-2">
					<p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
						Recomendaciones
					</p>
					<ul className="mt-3 space-y-2">
						{(summary.recommendations || []).map((rec, index) => (
							<li
								key={index}
								className="flex gap-2 text-sm text-zinc-700 dark:text-zinc-300"
							>
								<CheckCircleIcon className="mt-0.5 size-4 shrink-0 text-emerald-500" />
								<span>{rec}</span>
							</li>
						))}
					</ul>
					<div className="mt-4 flex items-start gap-2 text-xs text-zinc-500">
						<LightBulbIcon className="size-4 shrink-0 text-amber-500" />
						Resumen generado automáticamente a partir de las suites visibles.
					</div>
				</div>
			</div>
		</section>
	);
}
