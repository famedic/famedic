import clsx from "clsx";
import {
	ChartCard,
	TONE_CLASSES,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function MarketingIntelligenceCards({ items = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Marketing Intelligence
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Señales accionables para timing, fuentes y geografía.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
				{items.map((item) => {
					const tone = TONE_CLASSES[item.tone] || TONE_CLASSES.blue;
					return (
						<div
							key={item.id}
							className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
						>
							<div className="flex items-start gap-3">
								<span
									className={clsx(
										"mt-0.5 size-2.5 shrink-0 rounded-full",
										tone.bar,
									)}
								/>
								<div className="min-w-0">
									<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
										{item.label}
									</p>
									<p className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
										{item.value}
									</p>
									<p className="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
										{item.detail}
									</p>
								</div>
							</div>
						</div>
					);
				})}
			</div>
		</section>
	);
}
