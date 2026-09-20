import { useId, useState } from "react";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import {
	BeakerIcon,
	ClipboardDocumentListIcon,
	ChevronDownIcon,
	DocumentTextIcon,
	ExclamationTriangleIcon,
	MapPinIcon,
	SparklesIcon,
} from "@heroicons/react/24/outline";

const BRAND_LABELS = {
	swisslab: "Swisslab",
	olab: "Olab",
	jenner: "Jenner",
	liacsa: "Liacsa",
	azteca: "Azteca",
};

const PREPARATION_STATUS = {
	AI_READY: "AI_READY",
	AI_PENDING: "AI_PENDING",
	AI_FAILED: "AI_FAILED",
	FALLBACK: "FALLBACK",
};

function brandLabel(brand) {
	if (!brand) return "Laboratorio";
	const key = String(brand).toLowerCase();
	return BRAND_LABELS[key] || String(brand).replace(/_/g, " ");
}

function patientDisplayName(purchase) {
	if (purchase?.temporarly_hide_gda_order_id)
		return "Nombre de paciente pendiente";
	return purchase?.full_name || "—";
}

function studyInstructions(study) {
	return (study.indications || study.instructions || "").trim();
}

function studyHasInstructions(study) {
	const instructions = studyInstructions(study);
	return Boolean(instructions) && instructions !== "—";
}

function studiesWithInstructions(studies) {
	return (studies || []).filter(studyHasInstructions);
}

function InstructionCard({ title, emoji, children, id }) {
	return (
		<Card
			id={id}
			className="min-w-0 max-w-full scroll-mt-24 overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6"
		>
			<h3 className="mb-4 flex items-center gap-2 text-base font-semibold text-zinc-900 dark:text-white">
				{emoji && <span aria-hidden>{emoji}</span>}
				{title}
			</h3>
			<div className="space-y-3 text-sm leading-relaxed text-zinc-700 dark:text-slate-200">
				{children}
			</div>
		</Card>
	);
}

function EmptyInstructionsMessage() {
	return (
		<p className="text-sm text-zinc-500 dark:text-slate-400">
			No hay estudios con indicaciones de preparación para esta orden.
		</p>
	);
}

function PreparationSectionBlock({ section }) {
	const title = section?.title || "Indicaciones";
	const content = section?.content || "";

	if (!content.trim()) return null;

	return (
		<div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/50">
			<div className="mb-3 flex min-w-0 items-start gap-3">
				<span className="bg-famedic-50 text-famedic-700 dark:bg-famedic-950 dark:text-famedic-300 flex size-8 shrink-0 items-center justify-center rounded-full">
					<DocumentTextIcon className="size-4" aria-hidden />
				</span>
				<h4 className="min-w-0 pt-0.5 text-sm font-semibold leading-snug text-zinc-900 sm:text-base dark:text-white">
					{title}
				</h4>
			</div>
			<div className="whitespace-pre-wrap break-words pl-0 text-sm leading-relaxed text-zinc-700 sm:pl-11 dark:text-slate-200">
				{content}
			</div>
		</div>
	);
}

function IndividualInstructions({ studies, compact = false }) {
	const visibleStudies = studiesWithInstructions(studies);

	if (!visibleStudies.length) return <EmptyInstructionsMessage />;

	return (
		<div className="space-y-3">
			{visibleStudies.map((study, index) => {
				const instructions = studyInstructions(study);
				const featureList = Array.isArray(study.feature_list)
					? study.feature_list
					: [];

				return (
					<div
						key={`${study.name}-${index}`}
						className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900/70"
					>
						<p className="mb-2 flex items-start gap-2 font-semibold text-zinc-900 dark:text-white">
							<BeakerIcon
								className="text-famedic-600 dark:text-famedic-400 mt-0.5 size-4 shrink-0"
								aria-hidden
							/>
							<span className="min-w-0 break-words">
								{study.name || study.study_name || "Estudio"}
							</span>
						</p>
						{!compact && featureList.length > 0 && (
							<div className="mb-3 rounded-lg border border-amber-100 bg-amber-50/70 px-3 py-2 dark:border-amber-900/40 dark:bg-amber-950/30">
								<p className="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">
									Incluye en este paquete
								</p>
								<ul className="list-disc space-y-1 pl-4 text-xs text-amber-900 dark:text-amber-100">
									{featureList.map(
										(feature, featureIndex) => (
											<li
												key={`${feature}-${featureIndex}`}
											>
												{feature}
											</li>
										),
									)}
								</ul>
							</div>
						)}
						<div className="whitespace-pre-wrap break-words text-sm leading-relaxed text-zinc-700 dark:text-slate-200">
							{instructions}
						</div>
					</div>
				);
			})}
		</div>
	);
}

function SpecialInstructionsBlock({ instructions }) {
	if (!instructions.length) return null;

	return (
		<div className="rounded-xl border border-amber-200 bg-amber-50/80 p-4 sm:p-5 dark:border-amber-900/50 dark:bg-amber-950/30">
			<div className="mb-3 flex min-w-0 items-center gap-2">
				<span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">
					<ExclamationTriangleIcon className="size-4" aria-hidden />
				</span>
				<h4 className="min-w-0 text-sm font-semibold text-amber-950 sm:text-base dark:text-amber-100">
					Importante
				</h4>
			</div>
			<div className="space-y-3 text-sm leading-relaxed text-amber-950 dark:text-amber-100">
				{instructions.map((instruction, index) => (
					<p
						key={`${instruction.content}-${index}`}
						className="whitespace-pre-wrap break-words"
					>
						{instruction.content}
					</p>
				))}
			</div>
		</div>
	);
}

function PreparationSummary({ summary }) {
	const sections = Array.isArray(summary.sections) ? summary.sections : [];
	const specialInstructions = Array.isArray(summary.special_instructions)
		? summary.special_instructions.filter((instruction) =>
				String(instruction?.content || "").trim(),
			)
		: [];

	return (
		<div className="space-y-3">
			{sections.map((section, index) => (
				<PreparationSectionBlock
					key={section.key || `${section.title}-${index}`}
					section={section}
				/>
			))}
			<SpecialInstructionsBlock instructions={specialInstructions} />
		</div>
	);
}

function OriginalInstructionsDisclosure({ studies, defaultOpen = false }) {
	const [open, setOpen] = useState(defaultOpen);
	const reactId = useId();
	const contentId = `individual-preparation-${reactId.replace(/:/g, "")}`;
	const triggerId = `individual-preparation-trigger-${reactId.replace(/:/g, "")}`;
	const visibleStudies = studiesWithInstructions(studies);

	if (!visibleStudies.length) return null;

	return (
		<div className="border-t border-zinc-100 pt-4 dark:border-slate-800">
			<button
				id={triggerId}
				type="button"
				aria-expanded={open}
				aria-controls={contentId}
				onClick={() => setOpen((current) => !current)}
				className="focus:ring-famedic-500 flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-3 text-left transition hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:border-slate-700 dark:hover:bg-slate-800/50 dark:focus:ring-offset-slate-900"
			>
				<span className="flex min-w-0 items-start gap-3">
					<ClipboardDocumentListIcon
						className="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-slate-400"
						aria-hidden
					/>
					<span className="min-w-0">
						<span className="block text-sm font-semibold text-zinc-900 dark:text-white">
							Ver indicaciones originales
						</span>
						<span className="mt-0.5 block text-xs leading-relaxed text-zinc-500 dark:text-slate-400">
							Consulta las instrucciones específicas de cada
							estudio
						</span>
					</span>
				</span>
				<ChevronDownIcon
					className={`size-5 shrink-0 text-zinc-500 transition-transform duration-200 dark:text-slate-400 ${open ? "rotate-180" : ""}`}
					aria-hidden
				/>
			</button>
			<div
				id={contentId}
				role="region"
				aria-labelledby={triggerId}
				hidden={!open}
				className={open ? "mt-3" : undefined}
			>
				<IndividualInstructions studies={studies} compact />
			</div>
		</div>
	);
}

function PreparationOverview({ preparation, studies }) {
	const reactId = useId();
	const headingId = `preparation-heading-${reactId.replace(/:/g, "")}`;
	const aiStatus =
		preparation?.ai_status ||
		(preparation?.has_ai_summary
			? PREPARATION_STATUS.AI_READY
			: PREPARATION_STATUS.FALLBACK);
	const summary = preparation?.summary || {};
	const hasSummary = aiStatus === PREPARATION_STATUS.AI_READY;
	const showOriginalInstructionsAsMain = !hasSummary;
	const isPending = aiStatus === PREPARATION_STATUS.AI_PENDING;

	const introCopy = hasSummary
		? "Hemos simplificado las indicaciones de tus estudios para que sea más fácil prepararte."
		: isPending
			? "Revisa las indicaciones de tus estudios antes de acudir al laboratorio."
			: "Consulta las indicaciones de cada estudio antes de acudir al laboratorio.";

	return (
		<Card
			id="indicaciones-preparacion-estudios"
			className="min-w-0 max-w-full scroll-mt-24 overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6"
		>
			<div className="space-y-5">
				<div className="flex min-w-0 gap-3">
					<span className="bg-famedic-50 text-famedic-700 dark:bg-famedic-950 dark:text-famedic-300 flex size-10 shrink-0 items-center justify-center rounded-full">
						{hasSummary ? (
							<SparklesIcon className="size-5" aria-hidden />
						) : (
							<DocumentTextIcon className="size-5" aria-hidden />
						)}
					</span>
					<div className="min-w-0">
						<h3
							id={headingId}
							className="text-lg font-semibold leading-tight text-zinc-950 dark:text-white"
						>
							Preparación para tus estudios
						</h3>
						<p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-slate-300">
							{introCopy}
						</p>
					</div>
				</div>

				{hasSummary ? (
					<PreparationSummary summary={summary} />
				) : showOriginalInstructionsAsMain ? (
					<div className="space-y-4">
						<IndividualInstructions studies={studies} />
						{isPending && (
							<p className="text-sm leading-relaxed text-zinc-500 dark:text-slate-400">
								Estamos preparando un resumen más sencillo de
								estas indicaciones.
							</p>
						)}
					</div>
				) : null}

				{hasSummary && (
					<OriginalInstructionsDisclosure studies={studies} />
				)}
			</div>
		</Card>
	);
}

export default function InstructionsContent({
	purchase,
	orderType,
	hasAppointment,
	appointment,
	preparation,
	studiesWithIndications,
}) {
	const brand = brandLabel(purchase?.brand);
	const store = appointment?.laboratory_store;
	const appointmentDate =
		appointment?.formatted_appointment_date ||
		appointment?.appointment_date ||
		"—";
	const appointmentHour = appointment?.formatted_appointment_hour || null;
	const gdaOrder = purchase?.gda_order_id ?? "—";
	const gdaConsecutivo = purchase?.gda_consecutivo ?? "—";
	const birth = purchase?.formatted_birth_date || "—";
	const patient = patientDisplayName(purchase);
	const showSinCita =
		orderType === "without_appointment" || orderType === "mixed";
	const showConCita =
		(orderType === "with_appointment" || orderType === "mixed") &&
		hasAppointment;
	const preparationStudies =
		preparation?.studies || studiesWithIndications || [];

	const preparationCard = (
		<PreparationOverview
			preparation={preparation}
			studies={preparationStudies}
		/>
	);

	return (
		<div className="min-w-0 max-w-full space-y-6">
			{preparationCard}

			<InstructionCard title="Qué hacer en sucursal" emoji="🪪">
				<p className="font-medium text-zinc-900 dark:text-white">
					Presenta tu identificación en sucursal (muéstrala tal cual).
				</p>
				<ul className="mt-3 space-y-2 border-t border-zinc-100 pt-3 dark:border-slate-700">
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">
							🔹
						</span>
						<span>
							<strong>Consecutivo:</strong> {gdaConsecutivo}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">
							🔹
						</span>
						<span>
							<strong>Folio de orden:</strong> {gdaOrder}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">
							🔹
						</span>
						<span>
							<strong>Paciente:</strong> {patient}
						</span>
					</li>
					<li className="flex gap-2">
						<span className="text-famedic-600 dark:text-famedic-400">
							🔹
						</span>
						<span>
							<strong>Fecha de nacimiento:</strong> {birth}
						</span>
					</li>
				</ul>
			</InstructionCard>

			{showSinCita && (
				<InstructionCard title="Sin cita" emoji="🔎">
					<p className="font-medium">1. ¿A dónde puedes ir?</p>
					<p>
						Tus estudios no requieren cita. Puedes acudir en
						cualquier momento dentro del horario de atención de la
						sucursal.
					</p>
					<p className="text-zinc-600 dark:text-slate-400">
						Consulta sucursales, dirección, horarios (incluyendo
						domingos u horarios extraordinarios) y teléfono:
					</p>
					<Button
						outline
						href={route("laboratory-stores.index", {
							brand: purchase?.brand,
						})}
					>
						<MapPinIcon className="size-4" />
						Consultar sucursales, horarios y teléfono
					</Button>
				</InstructionCard>
			)}

			{showConCita && (
				<InstructionCard title="Datos de tu cita" emoji="📅">
					<ul className="space-y-2">
						<li className="flex gap-2">
							<span>🏥</span>
							<span>
								<strong>Laboratorio / marca:</strong> {brand}
							</span>
						</li>
						<li className="flex gap-2">
							<span>📆</span>
							<span>
								<strong>Fecha de la cita:</strong>{" "}
								{appointmentDate}
							</span>
						</li>
						{appointmentHour && (
							<li className="flex gap-2">
								<span>⏰</span>
								<span>
									<strong>Hora de la cita:</strong>{" "}
									{appointmentHour}
								</span>
							</li>
						)}
						{store?.name && (
							<li className="flex gap-2">
								<span>📍</span>
								<span>
									<strong>Sucursal de la cita:</strong>{" "}
									{store.name}
								</span>
							</li>
						)}
						{store?.address && (
							<li className="flex gap-2">
								<span>📌</span>
								<span>
									<strong>Dirección (si aplica):</strong>{" "}
									{store.address}
								</span>
							</li>
						)}
					</ul>
				</InstructionCard>
			)}

			<InstructionCard title="Antes de ir" emoji="🧠">
				<ul className="list-disc space-y-2 pl-5">
					<li>Llega 10 minutos antes.</li>
					<li>Lleva identificación oficial del paciente.</li>
					<li>
						Ten a la mano tu folio y los datos del paciente
						(arriba).
					</li>
				</ul>
			</InstructionCard>

			<InstructionCard title="Al llegar (paso a paso)" emoji="🚶‍♂️">
				<ol className="list-decimal space-y-3 pl-5">
					<li>
						Comparte tus identificadores: consecutivo, folio de
						orden, nombre del paciente y fecha de nacimiento.
					</li>
					<li>
						{hasAppointment && appointmentDate !== "—" ? (
							<>
								Confirma tu cita:{" "}
								<strong>{appointmentDate}</strong>
								{appointmentHour ? (
									<>
										{" "}
										<strong>{appointmentHour}</strong>
									</>
								) : null}{" "}
								en <strong>{brand}</strong>
								{store?.name ? (
									<>
										{" "}
										sucursal <strong>{store.name}</strong>
									</>
								) : null}
								.
							</>
						) : (
							<>
								Indica en recepción que vienes por tus estudios
								y muestra tu identificación.
							</>
						)}
					</li>
				</ol>
			</InstructionCard>
		</div>
	);
}
