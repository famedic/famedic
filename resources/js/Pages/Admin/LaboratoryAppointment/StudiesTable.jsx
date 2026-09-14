import clsx from "clsx";
import { Badge } from "@/Components/Catalyst/badge";
import { BanknotesIcon, TagIcon } from "@heroicons/react/24/outline";

const STATUS = {
	completed: { label: "Completado", color: "emerald" },
	pending: { label: "Pendiente", color: "amber" },
	cancelled: { label: "Cancelado", color: "red" },
};

export default function StudiesTable({ studies, compact = false }) {
	const allCompleted =
		studies.length > 0 &&
		studies.every((study) => study.status === "completed");

	return (
		<section className="min-w-0 rounded-xl border border-zinc-200/70 bg-white/85 p-3 shadow-sm sm:p-4 dark:border-zinc-800 dark:bg-zinc-900/70">
			<div className="mb-3 flex flex-wrap items-center justify-between gap-2">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Estudios incluidos
				</h3>
				<span className="text-xs text-zinc-500 dark:text-zinc-400">
					{studies.length} total
				</span>
			</div>

			{allCompleted && !compact && (
				<div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
					Todos los estudios de esta orden han sido completados.
				</div>
			)}

			{studies.length === 0 ? (
				<p className="text-sm text-zinc-500 dark:text-zinc-400">
					Sin estudios registrados.
				</p>
			) : (
				<div className="divide-y divide-zinc-200/70 overflow-hidden rounded-lg border border-zinc-200/70 dark:divide-zinc-800 dark:border-zinc-800">
					{studies.map((study, index) => (
						<StudyRow
							key={study.id}
							study={study}
							index={index}
							compact={compact}
						/>
					))}
				</div>
			)}
		</section>
	);
}

function StudyRow({ study, index, compact = false }) {
	const config = STATUS[study.status] ?? STATUS.pending;
	const description = study.description || null;
	const hasLongDescription = (description?.length ?? 0) > 160;

	return (
		<article className="min-w-0 bg-white p-3 transition hover:bg-zinc-50/80 dark:bg-zinc-900/40 dark:hover:bg-zinc-900">
			<div className="flex min-w-0 items-start gap-3">
				<span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
					{index + 1}
				</span>
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-start gap-2">
						<h4
							className={clsx(
								"min-w-0 flex-1 break-words text-sm font-semibold leading-snug text-zinc-950 dark:text-zinc-50",
								compact
									? "line-clamp-2"
									: "line-clamp-3 md:line-clamp-2",
							)}
							title={study.name}
						>
							{study.name}
						</h4>
						<Badge color={config.color}>{config.label}</Badge>
					</div>

					<div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
						<StudyMeta icon={TagIcon}>
							GDA {study.gdaId || "---"}
						</StudyMeta>
						<StudyMeta icon={BanknotesIcon}>
							{study.cost || "Sin costo registrado"}
						</StudyMeta>
					</div>

					{description ? (
						<div className="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
							<p
								className={clsx(
									"break-words",
									compact ? "line-clamp-1" : "line-clamp-2",
								)}
								title={description}
							>
								{description}
							</p>
							{hasLongDescription && !compact && (
								<details className="mt-1">
									<summary className="cursor-pointer text-xs font-medium text-sky-700 hover:text-sky-800 dark:text-sky-300 dark:hover:text-sky-200">
										Ver descripción completa
									</summary>
									<p className="mt-1 break-words text-sm text-zinc-700 dark:text-zinc-300">
										{description}
									</p>
								</details>
							)}
						</div>
					) : (
						<p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
							Sin descripción registrada.
						</p>
					)}
				</div>
			</div>
		</article>
	);
}

function StudyMeta({ icon: Icon, children }) {
	return (
		<span className="inline-flex min-w-0 items-center gap-1">
			<Icon className="size-3.5 shrink-0" />
			<span className="truncate">{children}</span>
		</span>
	);
}
