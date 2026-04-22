import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Link } from "@inertiajs/react";
import { BeakerIcon } from "@heroicons/react/24/outline";

const appointmentStateConfig = {
	pending: { label: "Pendiente", color: "amber" },
	scheduled: { label: "Agendada", color: "blue" },
	confirmed: { label: "Confirmada", color: "green" },
	not_applicable: { label: "No aplica", color: "zinc" },
};

const studyStateConfig = {
	pending: { label: "Pendiente", color: "amber" },
	in_progress: { label: "En proceso", color: "blue" },
	completed: { label: "Completado", color: "green" },
};

export default function StudiesTable({ studies, onOpenResults, onOpenPreparationInstructions }) {
	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-0 shadow-sm">
			<div className="flex min-w-0 flex-col gap-3 border-b border-zinc-200 px-3 py-3 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:px-5 sm:py-4">
				<h2 className="min-w-0 break-words text-lg font-semibold text-zinc-900 dark:text-white">
					Estudios solicitados
				</h2>
				{typeof onOpenPreparationInstructions === "function" && studies.length > 0 && (
					<Button
						outline
						type="button"
						className="h-auto w-full shrink-0 whitespace-normal py-2.5 text-left text-sm sm:w-auto sm:whitespace-nowrap"
						onClick={onOpenPreparationInstructions}
					>
						<BeakerIcon className="size-4 shrink-0" />
						<span className="min-w-0 leading-snug">Indicaciones de preparación (por estudio)</span>
					</Button>
				)}
			</div>
			{studies.length === 0 ? (
				<div className="px-5 py-10 text-center text-sm text-zinc-500 dark:text-slate-400">
					No hay estudios asociados a esta orden.
				</div>
			) : (
				<div className="max-w-full min-w-0 overflow-x-auto overscroll-x-contain [-webkit-overflow-scrolling:touch]">
					<table className="w-full min-w-[36rem] text-sm sm:min-w-0">
						<thead className="bg-zinc-50 dark:bg-slate-800/70">
							<tr className="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-slate-400">
								<th className="px-3 py-3 sm:px-4">Estudio</th>
								<th className="px-3 py-3 sm:px-4">Tipo</th>
								<th className="px-3 py-3 sm:px-4">Estado de cita</th>
								<th className="px-3 py-3 sm:px-4">Estado del estudio</th>
								<th className="px-3 py-3 text-right sm:px-4">Resultados</th>
							</tr>
						</thead>
						<tbody>
							{studies.map((study) => {
								const appointmentState = appointmentStateConfig[study.appointmentStatus];
								const studyState = studyStateConfig[study.studyStatus];
								return (
									<tr
										key={study.id}
										className="border-t border-zinc-100 transition hover:bg-zinc-50/70 dark:border-slate-800 dark:hover:bg-slate-800/50"
									>
										<td className="max-w-[200px] px-3 py-3 font-medium break-words text-zinc-900 dark:text-white sm:max-w-xs sm:px-4 md:max-w-md">
											{study.name}
										</td>
										<td className="px-3 py-3 sm:px-4">
											<Badge color={study.requiresAppointment ? "purple" : "blue"}>
												{study.requiresAppointment ? "Cita" : "Sin cita"}
											</Badge>
										</td>
										<td className="px-3 py-3 sm:px-4">
											<Badge color={appointmentState.color}>{appointmentState.label}</Badge>
										</td>
										<td className="px-3 py-3 sm:px-4">
											<Badge color={studyState.color}>{studyState.label}</Badge>
										</td>
										<td className="px-3 py-3 text-right sm:px-4">
											{study.resultsUrl ? (
												<Link
													href={study.resultsUrl}
													className="text-sm font-medium text-famedic-700 hover:underline dark:text-famedic-300"
												>
													Ver resultados
												</Link>
											) : (
												<Button outline type="button" onClick={onOpenResults}>
													Pendiente
												</Button>
											)}
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				</div>
			)}
		</Card>
	);
}
