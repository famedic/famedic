import clsx from "clsx";
import { useId, useState } from "react";
import {
	ExclamationTriangleIcon,
	InformationCircleIcon,
	CheckCircleIcon,
	LinkIcon,
	ChevronDownIcon,
} from "@heroicons/react/24/outline";

const PROCESS_STEPS = [
	"Sube tu constancia fiscal en PDF",
	"Extracción automática de datos",
	"Verifica y confirma los datos",
	"Solicita tus facturas sin problemas",
];

export default function TaxProfilesInfoPanel() {
	const [expanded, setExpanded] = useState(false);
	const panelId = useId();

	return (
		<section
			className="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-slate-900/5 transition-shadow duration-300 hover:shadow-md dark:border-slate-700/80 dark:bg-slate-900/90 dark:ring-white/10 dark:hover:shadow-lg dark:hover:shadow-blue-950/20"
			aria-label="Información sobre perfiles fiscales"
		>
			<button
				type="button"
				onClick={() => setExpanded((open) => !open)}
				aria-expanded={expanded}
				aria-controls={panelId}
				className={clsx(
					"group flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-200 sm:gap-4 sm:px-5 sm:py-3.5",
					"hover:bg-slate-50/80 dark:hover:bg-slate-800/60",
					expanded && "border-b border-slate-200/80 dark:border-slate-700/80",
				)}
			>
				<span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 ring-1 ring-blue-500/20 transition-transform duration-200 group-hover:scale-105 dark:bg-blue-400/15 dark:text-blue-300 dark:ring-blue-400/25">
					<InformationCircleIcon className="h-5 w-5" aria-hidden />
				</span>

				<span className="min-w-0 flex-1">
					<span className="block text-sm font-semibold text-slate-900 dark:text-white">
						Información importante
					</span>
					<span className="block text-xs text-slate-500 dark:text-slate-400">
						Ver detalles y recomendaciones
					</span>
				</span>

				<ChevronDownIcon
					className={clsx(
						"h-5 w-5 shrink-0 text-slate-400 transition-transform duration-300 ease-out dark:text-slate-500",
						"group-hover:text-blue-600 dark:group-hover:text-blue-300",
						expanded && "rotate-180",
					)}
					aria-hidden
				/>
			</button>

			<div
				id={panelId}
				className={clsx(
					"grid transition-[grid-template-rows] duration-300 ease-in-out",
					expanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]",
				)}
			>
				<div className="overflow-hidden">
					<div
						className={clsx(
							"border-t border-slate-200/80 bg-gradient-to-b from-slate-50/50 to-white px-4 py-4 transition-opacity duration-300 dark:border-slate-700/80 dark:from-slate-800/40 dark:to-slate-900/50",
							expanded ? "opacity-100" : "opacity-0",
						)}
					>
						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:gap-4">
							<InfoBlock
								icon={ExclamationTriangleIcon}
								iconClassName="text-amber-600 dark:text-amber-400"
								iconBgClassName="bg-amber-500/10 ring-amber-500/20 dark:bg-amber-400/10 dark:ring-amber-400/20"
								title="Datos correctos = Facturas"
								description="Los datos deben coincidir exactamente con tu constancia del SAT."
							/>

							<InfoBlock
								icon={InformationCircleIcon}
								iconClassName="text-blue-600 dark:text-blue-300"
								iconBgClassName="bg-blue-500/10 ring-blue-500/20 dark:bg-blue-400/15 dark:ring-blue-400/25"
								title="¿Qué necesitas?"
								description={
									<>
										<span className="block">RFC Persona Física</span>
										<span className="block">PDF vigente</span>
									</>
								}
							/>

							<InfoBlock
								icon={CheckCircleIcon}
								iconClassName="text-emerald-600 dark:text-emerald-400"
								iconBgClassName="bg-emerald-500/10 ring-emerald-500/20 dark:bg-emerald-400/10 dark:ring-emerald-400/20"
								title="Proceso rápido"
								description="4 pasos simples para facturar"
							>
								<ol className="mt-2 space-y-1.5">
									{PROCESS_STEPS.map((step, index) => (
										<li
											key={step}
											className="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300"
										>
											<span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-[10px] font-bold text-emerald-700 dark:bg-emerald-400/20 dark:text-emerald-300">
												{index + 1}
											</span>
											<span>{step}</span>
										</li>
									))}
								</ol>
							</InfoBlock>

							<div className="flex flex-col justify-between rounded-lg border border-blue-200/60 bg-gradient-to-br from-blue-50/80 to-indigo-50/50 p-3 dark:border-blue-500/25 dark:from-blue-950/40 dark:to-slate-900/60">
								<div>
									<p className="text-sm font-semibold text-blue-900 dark:text-white">
										¿Necesitas tu constancia del SAT?
									</p>
									<p className="mt-1 text-xs text-blue-700/90 dark:text-slate-400">
										Descárgala gratuitamente desde el portal oficial.
									</p>
								</div>
								<a
									href="https://wwwmat.sat.gob.mx/aplicacion/login/53027/genera-tu-constancia-de-situacion-fiscal"
									target="_blank"
									rel="noopener noreferrer"
									className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400 sm:w-auto"
								>
									<LinkIcon className="h-4 w-4" aria-hidden />
									Ir al SAT
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	);
}

function InfoBlock({
	icon: Icon,
	iconClassName,
	iconBgClassName,
	title,
	description,
	children,
}) {
	return (
		<div className="rounded-lg border border-slate-200/70 bg-white/80 p-3 dark:border-slate-700/70 dark:bg-slate-800/40">
			<div className="flex items-start gap-2.5">
				<span
					className={clsx(
						"flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1",
						iconBgClassName,
					)}
				>
					<Icon className={clsx("h-4 w-4", iconClassName)} aria-hidden />
				</span>
				<div className="min-w-0">
					<h3 className="text-sm font-semibold text-slate-900 dark:text-white">
						{title}
					</h3>
					<div className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
						{description}
					</div>
					{children}
				</div>
			</div>
		</div>
	);
}
