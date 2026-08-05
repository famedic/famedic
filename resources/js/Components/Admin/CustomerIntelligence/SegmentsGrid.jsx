import { router } from "@inertiajs/react";
import { Badge } from "@/Components/Catalyst/badge";

export default function SegmentsGrid({ segments = [], filters = {} }) {
	const applySegment = (segment) => {
		router.get(
			route("admin.customers.dormant"),
			{
				...filters,
				...segment.filter,
				tab: "clientes",
			},
			{ preserveState: true },
		);
	};

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Segmentación inteligente
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Segmentos listos para campaña. Clic para filtrar la tabla.
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
				{segments.map((segment) => (
					<button
						key={segment.id}
						type="button"
						onClick={() => applySegment(segment)}
						className="rounded-xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-famedic-light/40 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="flex items-start justify-between gap-3">
							<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{segment.label}
							</p>
							<Badge color="zinc">{Number(segment.count || 0).toLocaleString()}</Badge>
						</div>
						<p className="mt-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
							{segment.description}
						</p>
					</button>
				))}
			</div>
		</section>
	);
}
