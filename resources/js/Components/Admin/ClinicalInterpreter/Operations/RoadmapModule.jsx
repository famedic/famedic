import { Badge } from "@/Components/Catalyst/badge";

export default function RoadmapModule({ data }) {
	const items = data?.items || [];

	return (
		<div className="space-y-5">
			<p className="text-sm text-zinc-500">
				{data?.note || "Capacidades futuras · no implementadas."}
			</p>

			<ul className="grid gap-3 sm:grid-cols-2">
				{items.map((item) => (
					<li
						key={item.id}
						className="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/70 px-4 py-4 dark:border-zinc-600 dark:bg-zinc-950/40"
					>
						<div className="flex items-start justify-between gap-2">
							<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{item.title}
							</h3>
							<Badge color="zinc" className="!text-[10px]">
								Roadmap
							</Badge>
						</div>
						<p className="mt-2 text-sm text-zinc-500">{item.blurb}</p>
					</li>
				))}
			</ul>
		</div>
	);
}
