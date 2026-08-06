import clsx from "clsx";
import { TONE_CLASSES } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyPredictiveCards({ items = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					IA predictiva
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Segmentos de probabilidad, riesgo y recuperación.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{items.map((item) => {
					const tone = TONE_CLASSES[item.tone] || TONE_CLASSES.blue;
					return (
						<div
							key={item.id}
							className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex items-center justify-between gap-2">
								<span className={clsx("size-2.5 rounded-full", tone.bar)} />
								<span className="text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
									{item.percent}%
								</span>
							</div>
							<p className="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{item.label}
							</p>
							<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-800 dark:text-zinc-100">
								{Number(item.count || 0).toLocaleString()}
							</p>
							<p className="mt-1 text-xs text-zinc-500">{item.description}</p>
						</div>
					);
				})}
			</div>
		</section>
	);
}
