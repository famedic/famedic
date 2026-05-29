import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import {
	CalendarDaysIcon,
	ChatBubbleLeftEllipsisIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";

export default function PatientCallbackInfoCard({
	appointment,
	callbackPreferenceSavedAtFormatted = null,
}) {
	const hasPhoneIntent = Boolean(appointment.formatted_phone_call_intent_at);
	const hasCallbackInfo = Boolean(appointment.has_left_callback_info);
	const hasRequest = Boolean(appointment.formatted_request_saved_at);
	const hasAnyActivity = hasPhoneIntent || hasCallbackInfo || hasRequest;

	return (
		<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<div className="mb-4 flex flex-wrap items-center justify-between gap-2">
				<h3 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Solicitud y disponibilidad para llamada
				</h3>
				{hasCallbackInfo && (
					<Badge color="emerald">Disponibilidad registrada</Badge>
				)}
				{hasPhoneIntent && !hasCallbackInfo && (
					<Badge color="sky">Intentó llamar</Badge>
				)}
			</div>

			{!hasAnyActivity ? (
				<Text className="text-sm text-zinc-500 dark:text-zinc-400">
					El paciente aún no ha intentado llamar ni ha indicado
					disponibilidad para recibir la llamada.
				</Text>
			) : (
				<div className="space-y-4">
					{hasRequest && (
						<InfoBlock
							icon={CalendarDaysIcon}
							label="Solicitud de cita"
							value={appointment.formatted_request_saved_at}
						/>
					)}

					{hasPhoneIntent && (
						<InfoBlock
							icon={PhoneIcon}
							label="Intento de llamada"
							value={
								<>
									{appointment.formatted_phone_call_intent_at}
									{appointment.time_since_phone_intent_human && (
										<span className="text-zinc-500 dark:text-zinc-400">
											{" "}
											({appointment.time_since_phone_intent_human})
										</span>
									)}
								</>
							}
						/>
					)}

					{hasCallbackInfo && (
						<>
							{appointment.formatted_callback_availability_range && (
								<InfoBlock
									icon={CalendarDaysIcon}
									label="Disponibilidad para recibir llamada"
									value={
										appointment.formatted_callback_availability_range
									}
								/>
							)}

							{appointment.patient_callback_comment && (
								<InfoBlock
									icon={ChatBubbleLeftEllipsisIcon}
									label="Comentarios del paciente"
									value={appointment.patient_callback_comment}
								/>
							)}

							{callbackPreferenceSavedAtFormatted && (
								<Text className="text-xs text-zinc-500 dark:text-zinc-400">
									<Strong>Última actualización:</Strong>{" "}
									{callbackPreferenceSavedAtFormatted}
								</Text>
							)}
						</>
					)}
				</div>
			)}
		</section>
	);
}

function InfoBlock({ icon: Icon, label, value }) {
	return (
		<div className="rounded-xl border border-zinc-200/70 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
			<p className="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				<Icon className="size-4" />
				{label}
			</p>
			<p className="mt-2 text-sm text-zinc-900 dark:text-zinc-100">{value}</p>
		</div>
	);
}
