import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

const ITEMS = [
	{
		key: "disponible",
		badge: { color: "emerald", label: "Disponible" },
		title: "Disponible",
	},
	{
		key: "proxy",
		badge: { color: "amber", label: "Proxy" },
		title: "Proxy",
	},
	{
		key: "instrumentacion",
		badge: { color: "violet", label: "Requiere sync" },
		title: "Instrumentación",
	},
];

export default function DashboardDefinitions({ definitions = {} }) {
	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						Leyenda
					</p>
					<h2 className="mt-1 font-poppins text-base font-semibold tracking-tight text-zinc-950 dark:text-white">
						Definiciones de verdad de datos
					</h2>
				</div>
				<div className="flex flex-wrap gap-2">
					{ITEMS.map((item) => (
						<Badge key={item.key} color={item.badge.color}>
							{item.badge.label}
						</Badge>
					))}
				</div>
			</div>

			<ul className="mt-4 grid gap-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400 md:grid-cols-3">
				{ITEMS.map((item) => (
					<li
						key={item.key}
						className="rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 dark:border-zinc-800 dark:bg-zinc-950/40"
					>
						<span className="font-semibold text-zinc-800 dark:text-zinc-200">
							{item.title}
						</span>
						<Text className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
							{definitions[item.key] || "—"}
						</Text>
					</li>
				))}
			</ul>
		</section>
	);
}
