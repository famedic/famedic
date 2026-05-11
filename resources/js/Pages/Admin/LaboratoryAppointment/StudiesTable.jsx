import { Badge } from "@/Components/Catalyst/badge";

const STATUS = {
	completed: { label: "Completado", color: "emerald" },
	pending: { label: "Pendiente", color: "amber" },
	cancelled: { label: "Cancelado", color: "red" },
};

export default function StudiesTable({ studies }) {
	const allCompleted =
		studies.length > 0 && studies.every((study) => study.status === "completed");

	return (
		<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<div className="mb-4 flex items-center justify-between">
				<h3 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Estudios incluidos
				</h3>
				<span className="text-xs text-zinc-500 dark:text-zinc-400">
					{studies.length} total
				</span>
			</div>

			{allCompleted && (
				<div className="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">
					Todos los estudios de esta orden han sido completados.
				</div>
			)}

			<div className="overflow-x-auto">
				<table className="w-full min-w-[680px] text-sm">
					<thead>
						<tr className="border-b border-zinc-200/70 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
							<th className="pb-2">Nombre del estudio</th>
							<th className="pb-2">Estado</th>
							<th className="pb-2">Tipo de muestra</th>
							<th className="pb-2">Fecha/hora de realizacion</th>
						</tr>
					</thead>
					<tbody>
						{studies.map((study) => {
							const config = STATUS[study.status] ?? STATUS.pending;

							return (
								<tr
									key={study.id}
									className="border-b border-zinc-200/60 dark:border-zinc-800"
								>
									<td className="py-3 text-zinc-900 dark:text-zinc-100">
										{study.name}
									</td>
									<td className="py-3">
										<Badge color={config.color}>{config.label}</Badge>
									</td>
									<td className="py-3 text-zinc-600 dark:text-zinc-300">
										{study.sampleType || "No especificado"}
									</td>
									<td className="py-3 text-zinc-600 dark:text-zinc-300">
										{study.performedAt || "Pendiente"}
									</td>
								</tr>
							);
						})}
					</tbody>
				</table>
			</div>
		</section>
	);
}
