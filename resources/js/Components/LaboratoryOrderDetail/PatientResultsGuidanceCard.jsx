import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	resolveEffectiveResultStatus,
} from "@/lib/laboratoryPurchaseResultUi";
import {
	ArrowPathIcon,
	CheckCircleIcon,
	ClockIcon,
	EyeIcon,
	InformationCircleIcon,
} from "@heroicons/react/24/outline";

function patientGuidance(resultControl, hasResults) {
	const status = resolveEffectiveResultStatus(resultControl, hasResults);

	const guidance = {
		complete: {
			title: "Tus resultados están listos",
			description:
				"Todos tus estudios ya tienen interpretación final. Puedes consultarlos de forma segura desde este pedido.",
			badge: "Listo",
			badgeColor: "green",
			icon: CheckCircleIcon,
			tips: ["Te avisamos por correo cuando hay novedades.", "Si tienes dudas, contáctanos por Soporte."],
		},
		legacy_available: {
			title: "Ya hay un documento para ti",
			description:
				"El laboratorio compartió un PDF de resultados. Puedes abrirlo mientras completamos el detalle por estudio.",
			badge: "Disponible",
			badgeColor: "green",
			icon: CheckCircleIcon,
			tips: ["Usa el botón de abajo o el panel Resultados a la derecha."],
		},
		pending_interpretation: {
			title: "Estamos preparando tus resultados",
			description:
				"El laboratorio ya está procesando tus estudios. Te avisaremos cuando la interpretación esté lista.",
			badge: "En proceso",
			badgeColor: "amber",
			icon: ClockIcon,
			tips: [
				"Te avisaremos por correo en cuanto estén listos.",
				"Mientras tanto, revisa la pestaña Instrucciones.",
			],
		},
		awaiting_sample: {
			title: "Primero acude a tu toma de muestra",
			description:
				"Tus resultados aparecerán aquí después de que el laboratorio confirme la toma de muestra. Hasta entonces, el siguiente paso es presentarte en sucursal o a tu cita.",
			badge: "Toma de muestra",
			badgeColor: "sky",
			icon: ClockIcon,
			tips: [
				"Revisa Instrucciones para preparación y datos de sucursal.",
				"Cuando confirmen la toma de muestra, esta tarjeta cambiará a resultados en proceso.",
			],
		},
		manual_review: {
			title: "Tu orden está en revisión",
			description:
				"Nuestro equipo valida tus resultados para asegurarnos de que todo esté correcto antes de avisarte.",
			badge: "En revisión",
			badgeColor: "amber",
			icon: ClockIcon,
			tips: ["Si necesitamos algo adicional, te contactaremos."],
		},
		error: {
			title: "Estamos atendiendo tu orden",
			description:
				"Hubo un detalle al sincronizar con el laboratorio. Nuestro equipo ya lo está revisando.",
			badge: "En seguimiento",
			badgeColor: "amber",
			icon: InformationCircleIcon,
			tips: ["No necesitas hacer nada por ahora.", "Te avisaremos cuando haya actualización."],
		},
		pending: {
			title: "Tus resultados están en proceso",
			description:
				"Ya registramos tu toma de muestra. El laboratorio está procesando tus estudios y te avisaremos cuando estén listos.",
			badge: "En proceso",
			badgeColor: "amber",
			icon: ClockIcon,
			tips: ["Te avisaremos por correo en cuanto haya novedades."],
		},
	};

	return guidance[status] || guidance.pending;
}

export default function PatientResultsGuidanceCard({
	resultControl,
	hasResults = false,
	onViewResults,
	isProcessing = false,
	onOpenInstructions,
}) {
	if (!resultControl) return null;

	const guidance = patientGuidance(resultControl, hasResults);
	const Icon = guidance.icon;
	const canView = Boolean(hasResults && resultControl.can_view_results && onViewResults);
	const buttonLabel = resultControl.button_label || "Ver resultados";

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl border border-sky-100 bg-gradient-to-br from-sky-50/80 to-white p-4 shadow-sm dark:border-sky-900/40 dark:from-sky-950/20 dark:to-slate-900 sm:p-6">
			<div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-center gap-2">
						<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white text-sky-700 shadow-sm dark:bg-slate-800 dark:text-sky-300">
							<Icon className="size-5" aria-hidden="true" />
						</div>
						<div className="min-w-0">
							<h2 className="text-base font-semibold text-zinc-900 dark:text-white">
								{guidance.title}
							</h2>
							<Badge color={guidance.badgeColor} className="mt-1">
								{guidance.badge}
							</Badge>
						</div>
					</div>
					<p className="mt-3 text-sm leading-relaxed text-zinc-700 dark:text-slate-200">
						{guidance.description}
					</p>
					{guidance.tips?.length > 0 && (
						<ul className="mt-3 space-y-1.5 text-sm text-zinc-600 dark:text-slate-400">
							{guidance.tips.map((tip) => (
								<li key={tip} className="flex gap-2">
									<span className="text-sky-600 dark:text-sky-400" aria-hidden="true">
										•
									</span>
									<span>{tip}</span>
								</li>
							))}
						</ul>
					)}
				</div>
			</div>

			<div className="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
				{canView && (
					<Button
						color="famedic-lime"
						type="button"
						className="w-full justify-center sm:w-auto"
						disabled={isProcessing}
						onClick={onViewResults}
					>
						{isProcessing ? (
							<>
								<ArrowPathIcon className="size-4 animate-spin" />
								Abriendo...
							</>
						) : (
							<>
								<EyeIcon className="size-4" />
								{buttonLabel}
							</>
						)}
					</Button>
				)}
				{typeof onOpenInstructions === "function" && (
					<Button
						outline
						type="button"
						className="w-full justify-center sm:w-auto"
						onClick={onOpenInstructions}
					>
						Ver instrucciones
					</Button>
				)}
			</div>
		</Card>
	);
}
