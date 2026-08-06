import clsx from "clsx";
import { DATA_SOURCES, LEGEND_SOURCES } from "./dataProvenanceConstants";

export default function DataLegend({
	title = "Fuentes de información",
	sources = LEGEND_SOURCES,
	className = "",
	sticky = true,
}) {
	const items = sources
		.map((key) => DATA_SOURCES[key])
		.filter(Boolean);

	return (
		<section
			className={clsx(
				"rounded-2xl border border-zinc-200 bg-white/90 p-4 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90",
				sticky ? "sticky top-0 z-20" : "",
				className,
			)}
			aria-label={title}
		>
			<p className="mb-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">
				{title}
			</p>
			<ul className="flex flex-wrap gap-x-4 gap-y-2">
				{items.map((item) => (
					<li key={item.key} className="inline-flex items-center gap-2 text-xs">
						<span className={clsx("size-2.5 rounded-full", item.dot)} aria-hidden />
						<span className="font-medium text-zinc-800 dark:text-zinc-100">
							{item.legend}
						</span>
					</li>
				))}
			</ul>
			<p className="mt-2 text-[10px] text-zinc-400">
				Cada KPI indica fuente, modo de lectura y calidad (A–F). Hover en el badge
				para endpoint, TTL y última actualización.
			</p>
		</section>
	);
}
