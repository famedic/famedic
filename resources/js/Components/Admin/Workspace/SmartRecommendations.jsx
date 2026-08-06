import { LightBulbIcon, SparklesIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";

export default function SmartRecommendations({ recommendations }) {
	if (!recommendations) {
		return null;
	}

	return (
		<section className="rounded-[1.75rem] border border-zinc-200 bg-zinc-50/70 p-6 dark:border-zinc-800 dark:bg-zinc-900/40">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<p className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						<SparklesIcon className="size-3.5 text-violet-500" />
						{recommendations.title || "Recomendaciones Inteligentes"}
					</p>
					<ul className="mt-4 space-y-2.5">
						{(recommendations.items || []).map((item, index) => (
							<li
								key={index}
								className="flex gap-2.5 text-sm text-zinc-700 dark:text-zinc-300"
							>
								<span className="text-zinc-400">•</span>
								<span className="leading-relaxed">{item}</span>
							</li>
						))}
					</ul>
				</div>
				{recommendations.cta_href ? (
					<Button href={recommendations.cta_href} outline>
						{recommendations.cta_label || "Ver recomendaciones"}
					</Button>
				) : null}
			</div>
			<div className="mt-4 flex items-start gap-2 text-xs text-zinc-500">
				<LightBulbIcon className="size-4 shrink-0 text-amber-500" />
				Sugerencias generadas a partir de tu actividad y workspaces disponibles.
			</div>
		</section>
	);
}
