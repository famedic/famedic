import clsx from "clsx";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	CalendarDaysIcon,
	ChatBubbleLeftEllipsisIcon,
	CheckIcon,
	ClockIcon,
	MapPinIcon,
} from "@heroicons/react/24/outline";

export default function PatientCallbackInfoCard({
	appointment,
	callbackPreferenceSavedAtFormatted = null,
	onSchedule = null,
	scheduleLabel = "Agendar cita",
}) {
	const hasRequest = Boolean(appointment.formatted_request_saved_at);
	const hasAvailability = Boolean(
		appointment.has_left_callback_info ||
			appointment.formatted_callback_availability_range,
	);
	const hasAppointment = Boolean(
		appointment.confirmed_at || appointment.formatted_appointment_date,
	);
	const storeName = appointment.laboratory_store?.name;
	const storeAddress = appointment.laboratory_store?.address;

	const steps = [
		{
			id: "request",
			title: "Solicitud realizada",
			statusLabel: hasRequest ? "Completado" : "Sin registro",
			done: hasRequest,
			icon: CalendarDaysIcon,
			primary:
				appointment.formatted_request_saved_at ??
				"Sin fecha de solicitud registrada",
			secondary: appointment.time_since_request_human ?? null,
			description: hasRequest
				? "El paciente solicitó esta cita de laboratorio."
				: "Aún no hay una fecha de solicitud disponible.",
		},
		{
			id: "availability",
			title: hasAvailability
				? "Disponibilidad registrada"
				: "Disponibilidad pendiente",
			statusLabel: hasAvailability ? "Completado" : "Pendiente",
			done: hasAvailability,
			icon: ClockIcon,
			primary:
				appointment.formatted_callback_availability_range ??
				"Disponibilidad pendiente",
			updatedAt: callbackPreferenceSavedAtFormatted
				? `Actualizado ${callbackPreferenceSavedAtFormatted}`
				: null,
			comment: appointment.patient_callback_comment ?? null,
			description: hasAvailability
				? "El paciente indicó cuándo puede recibir seguimiento."
				: "Falta registrar la disponibilidad del paciente.",
		},
		{
			id: "appointment",
			title: hasAppointment ? "Cita agendada" : "Cita",
			statusLabel: hasAppointment ? "Completado" : "Pendiente",
			done: hasAppointment,
			icon: MapPinIcon,
			primary:
				appointment.formatted_appointment_date ??
				"Cita pendiente de agendar",
			secondary: storeName ?? "Sin sucursal asignada",
			tertiary: hasAppointment && storeAddress ? storeAddress : null,
			description: hasAppointment
				? "La cita ya tiene fecha y sucursal asignadas."
				: "Siguiente acción: agendar cita con el laboratorio.",
		},
	];

	const nextStep = steps.find((step) => !step.done);

	return (
		<section className="rounded-xl border border-zinc-200/70 bg-white/85 p-4 shadow-sm sm:p-6 dark:border-zinc-800 dark:bg-zinc-900/70">
			<div className="mb-7 flex flex-wrap items-start justify-between gap-4">
				<div className="min-w-0">
					<h3 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						Solicitud y disponibilidad
					</h3>
					<p className="mt-2 text-lg font-semibold text-zinc-950 dark:text-zinc-50">
						Historia de la cita
					</p>
					<p className="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
						Revisa qué ocurrió, cuándo ocurrió y qué necesita el
						concierge para avanzar.
					</p>
				</div>
				<Badge
					color={
						hasAppointment
							? "emerald"
							: hasAvailability
								? "sky"
								: "amber"
					}
				>
					{hasAppointment
						? "Cita agendada"
						: hasAvailability
							? "Lista para agendar"
							: "Pendiente"}
				</Badge>
			</div>

			<div className="mx-auto max-w-3xl">
				<ol className="space-y-0">
					{steps.map((step, index) => (
						<TimelineStep
							key={step.id}
							step={step}
							isLast={index === steps.length - 1}
							isNext={nextStep?.id === step.id}
							onSchedule={onSchedule}
							scheduleLabel={scheduleLabel}
						/>
					))}
				</ol>
			</div>
		</section>
	);
}

function TimelineStep({
	step,
	isLast = false,
	isNext = false,
	onSchedule = null,
	scheduleLabel = "Agendar cita",
}) {
	const Icon = step.icon;
	const showScheduleAction =
		step.id === "appointment" && !step.done && onSchedule;

	return (
		<li className="relative min-w-0 pb-8 pl-12 last:pb-0">
			{!isLast && (
				<div
					className="absolute left-4 top-9 h-[calc(100%-2.25rem)] w-px bg-zinc-200 dark:bg-zinc-800"
					aria-hidden="true"
				/>
			)}
			<div
				className={clsx(
					"absolute left-0 top-0 z-10 flex size-8 items-center justify-center rounded-full border bg-white dark:bg-zinc-950",
					step.done
						? "border-emerald-500 text-emerald-600 dark:text-emerald-400"
						: "border-zinc-300 text-zinc-400 dark:border-zinc-700",
				)}
			>
				{step.done ? (
					<CheckIcon className="size-4" />
				) : (
					<Icon className="size-4" />
				)}
			</div>

			<div className="min-w-0 rounded-lg border border-zinc-200/70 bg-zinc-50/70 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950/30">
				<div className="flex flex-wrap items-start justify-between gap-2">
					<div className="min-w-0">
						<p className="text-sm font-semibold text-zinc-950 dark:text-zinc-50">
							{step.title}
						</p>
						{step.description && (
							<p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
								{step.description}
							</p>
						)}
					</div>
					<Badge
						color={step.done ? "emerald" : isNext ? "sky" : "zinc"}
					>
						{step.statusLabel}
					</Badge>
				</div>

				<p
					className="mt-3 break-words text-sm font-medium text-zinc-800 dark:text-zinc-200"
					title={step.primary}
				>
					{step.primary}
				</p>
				{step.secondary && (
					<p
						className="mt-1 line-clamp-2 break-words text-sm text-zinc-600 dark:text-zinc-300"
						title={step.secondary}
					>
						{step.secondary}
					</p>
				)}
				{step.tertiary && (
					<p
						className="mt-1 line-clamp-2 break-words text-xs text-zinc-500 dark:text-zinc-400"
						title={step.tertiary}
					>
						{step.tertiary}
					</p>
				)}
				{step.comment && (
					<>
						<div className="mt-4 rounded-lg border border-sky-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
							<p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								<ChatBubbleLeftEllipsisIcon className="size-3.5" />
								Comentario del paciente
							</p>
							<p
								className="mt-1 line-clamp-3 break-words"
								title={step.comment}
							>
								{step.comment}
							</p>
						</div>
						{step.updatedAt && (
							<p
								className="mt-1.5 text-[11px] leading-4 text-zinc-400 dark:text-zinc-500"
								title={step.updatedAt}
							>
								{step.updatedAt}
							</p>
						)}
					</>
				)}
				{isNext && (
					<div className="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 dark:border-sky-500/30 dark:bg-sky-500/10">
						<p className="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-200">
							Siguiente acción
						</p>
						<p className="mt-1 text-sm font-medium text-sky-950 dark:text-sky-100">
							{step.id === "appointment"
								? "Agendar cita"
								: step.title}
						</p>
						{showScheduleAction && (
							<Button className="mt-3" onClick={onSchedule}>
								<CalendarDaysIcon />
								{scheduleLabel}
							</Button>
						)}
					</div>
				)}
			</div>
		</li>
	);
}
