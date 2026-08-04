import { Badge } from "@/Components/Catalyst/badge";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
	instrumentacion: { label: "Requiere instrumentación", color: "violet" },
};

export default function JourneyLegend() {
	return (
		<div className="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<span className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
				Leyenda
			</span>
			{Object.values(TRUTH).map((item) => (
				<Badge key={item.label} color={item.color}>
					{item.label}
				</Badge>
			))}
			<span className="text-xs text-zinc-500 dark:text-zinc-400">
				Nodos sólidos = eventos Famedic · Nodos punteados = no instrumentados
			</span>
		</div>
	);
}
