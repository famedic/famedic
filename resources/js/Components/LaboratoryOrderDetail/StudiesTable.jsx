import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { BeakerIcon, CheckCircleIcon, ClockIcon, LockClosedIcon } from "@heroicons/react/24/outline";

export default function StudiesTable({ studies, onOpenPreparationInstructions }) {
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
					<table className="w-full min-w-0 text-sm">
						<thead className="bg-zinc-50 dark:bg-slate-800/70">
							<tr className="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-slate-400">
								<th className="px-3 py-3 sm:px-4">Estudio</th>
								<th className="px-3 py-3 sm:px-4">Precio</th>
								<th className="px-3 py-3 text-right sm:px-4">Resultados</th>
							</tr>
						</thead>
						<tbody>
							{studies.map((study) => (
								<tr
									key={study.id}
									className="border-t border-zinc-100 transition hover:bg-zinc-50/70 dark:border-slate-800 dark:hover:bg-slate-800/50"
								>
									<td className="max-w-[200px] px-3 py-3 font-medium break-words text-zinc-900 dark:text-white sm:max-w-xs sm:px-4 md:max-w-md">
										{study.name}
									</td>
									<td className="whitespace-nowrap px-3 py-3 tabular-nums text-zinc-900 dark:text-white sm:px-4">
										{study.formattedPrice}
									</td>
									<td className="px-3 py-3 text-right sm:px-4">
										{study.resultsUrl ? (
											<div className="inline-flex flex-col items-end gap-1">
												<Badge color="green" className="inline-flex items-center gap-1">
													<CheckCircleIcon className="size-3.5" />
													Disponible
												</Badge>
												<span className="inline-flex items-center gap-1 text-[11px] text-zinc-500 dark:text-slate-400">
													<LockClosedIcon className="size-3" />
													Protegido OTP
												</span>
											</div>
										) : (
											<Badge color="amber" className="inline-flex items-center gap-1">
												<ClockIcon className="size-3.5" />
												Pendiente
											</Badge>
										)}
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</Card>
	);
}
