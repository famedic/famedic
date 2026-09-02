import { Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { WhatsAppIcon } from "@/Components/Checkout/CheckoutWhatsAppHelp";
import {
	BoltIcon,
	CheckIcon,
	ChevronDownIcon,
	CreditCardIcon,
	LockClosedIcon,
	PhoneIcon,
} from "@heroicons/react/20/solid";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { useState, useEffect, useMemo, useRef } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import clsx from "clsx";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import getConciergeAvailability from "@/Utils/getConciergeAvailability";

function toDatetimeLocal(value) {
	if (!value) return "";
	const d = new Date(value);
	if (Number.isNaN(d.getTime())) return "";
	const pad = (n) => String(n).padStart(2, "0");
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function minStartDatetimeLocal() {
	const d = new Date();
	d.setSeconds(0, 0);
	d.setMilliseconds(0);
	d.setMinutes(d.getMinutes() + 1);
	return toDatetimeLocal(d);
}

function pad2(n) {
	return String(n).padStart(2, "0");
}

function getDefaultHourWindow(dayOffset = 0) {
	const base = new Date();
	base.setSeconds(0, 0);

	const start = new Date(base);
	start.setDate(start.getDate() + dayOffset);
	start.setHours(base.getHours() + 1, 0, 0, 0);

	const end = new Date(start);
	end.setHours(end.getHours() + 1, 0, 0, 0);

	return {
		startAt: toDatetimeLocal(start),
		endAt: toDatetimeLocal(end),
		startTime: `${pad2(start.getHours())}:${pad2(start.getMinutes())}`,
		endTime: `${pad2(end.getHours())}:${pad2(end.getMinutes())}`,
	};
}

function toDayOffsetOption(value) {
	const map = { today: 0, tomorrow: 1, day_after_tomorrow: 2 };
	return map[value] ?? 0;
}

function getReceiveCallCopy(isConciergeAvailable) {
	if (isConciergeAvailable) {
		return {
			title: "¿Cuándo quieres que te llamemos?",
			description: "Podemos llamarte ahora o en otro momento.",
			nowOptionLabel: "Lo antes posible",
			laterOptionLabel: "Elegir otro horario",
		};
	}

	return {
		title: "Déjanos tus datos para llamarte",
		description:
			"Nuestro equipo te contactará en el siguiente horario disponible.",
		nowOptionLabel: "En el siguiente horario disponible",
		laterOptionLabel: "Elegir otro horario",
	};
}

function getSaveButtonLabel(hasSavedAvailability, isSubmitting) {
	if (isSubmitting) {
		return hasSavedAvailability ? "Actualizando…" : "Guardando…";
	}

	return hasSavedAvailability
		? "Actualizar disponibilidad"
		: "Guardar disponibilidad";
}

function getAppointmentVisualState({
	hasSavedAvailability,
	appointmentConfirmed,
	appointmentUnavailable,
}) {
	if (appointmentConfirmed) {
		return {
			title: "Tu cita está confirmada",
			description:
				"Tu cita quedó agendada. Ya puedes continuar con el pago.",
			indicator: "Continuar al pago",
		};
	}

	if (appointmentUnavailable) {
		return {
			title: "Cita no disponible",
			description:
				"Necesitamos actualizar tu disponibilidad para gestionar una nueva cita por teléfono.",
			indicator: "Requiere atención",
		};
	}

	if (hasSavedAvailability) {
		return {
			title: "Solicitud de llamada registrada",
			description:
				"Nuestro equipo te llamará en el horario indicado para confirmar y agendar tu cita.",
			indicator: "Esperando llamada",
		};
	}

	return {
		title: "Confirma tu cita por WhatsApp",
		description:
			"Escríbenos para elegir fecha, horario y sucursal. El pago se habilitará después de confirmar.",
		indicator: "Contacto pendiente",
	};
}

function AppointmentAvailabilityBadge({ isAvailable }) {
	const label = isAvailable
		? "Equipo disponible ahora"
		: "Equipo fuera de horario";

	return (
		<div
			className={clsx(
				"inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold",
				isAvailable
					? "bg-emerald-50 text-emerald-900 ring-1 ring-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-100 dark:ring-emerald-800/70"
					: "bg-zinc-100 text-zinc-800 ring-1 ring-zinc-200 dark:bg-zinc-800/70 dark:text-zinc-100 dark:ring-zinc-700",
			)}
			role="status"
			aria-label={label}
		>
			<span
				className={clsx(
					"size-2.5 shrink-0 rounded-full",
					isAvailable ? "bg-green-500" : "bg-amber-400",
				)}
				aria-hidden="true"
			/>
			<span>{label}</span>
		</div>
	);
}

function AppointmentHeaderRow({ isAvailable }) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-3">
			<AppointmentAvailabilityBadge isAvailable={isAvailable} />
			{isAvailable && (
				<p className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">
					<BoltIcon
						className="size-4 text-amber-500"
						aria-hidden="true"
					/>
					Atención inmediata
				</p>
			)}
		</div>
	);
}

function AppointmentStatusSummary({
	hasSavedAvailability,
	appointmentConfirmed,
	appointmentUnavailable,
}) {
	const visualState = getAppointmentVisualState({
		hasSavedAvailability,
		appointmentConfirmed,
		appointmentUnavailable,
	});

	return (
		<div>
			<h3 className="text-xl font-semibold tracking-normal text-zinc-950 sm:text-2xl dark:text-white">
				{visualState.title}
			</h3>
			<p className="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-400">
				{visualState.description}
			</p>
		</div>
	);
}

function OptionCard({ selected, label, onClick }) {
	return (
		<button
			type="button"
			onClick={onClick}
			aria-pressed={selected}
			className={clsx(
				"flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition-colors",
				selected
					? "border-sky-500 bg-sky-50 dark:border-sky-500 dark:bg-sky-900/20"
					: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600",
			)}
		>
			<span className="font-medium">{label}</span>
			{selected ? (
				<CheckIcon className="size-5 text-sky-700 dark:text-sky-300" />
			) : (
				<span className="size-5" aria-hidden="true" />
			)}
		</button>
	);
}

const APPOINTMENT_PROGRESS_STEPS = [
	{
		id: "request",
		label: "Solicitud",
		defaultCaption: "Registrada",
		icon: CheckIcon,
	},
	{
		id: "phone",
		label: "Contacto",
		defaultCaption: "Siguiente paso",
		savedCaption: "Llamada solicitada",
		icon: WhatsAppIcon,
	},
	{
		id: "payment",
		label: "Pago",
		defaultCaption: "Al confirmar",
		confirmedCaption: "Disponible",
		icon: CreditCardIcon,
	},
];

function AppointmentProgressSteps({
	hasRequestSaved,
	hasSavedAvailability,
	appointmentConfirmed,
	appointmentUnavailable,
}) {
	const payable = appointmentConfirmed && !appointmentUnavailable;
	const activeIndex = payable ? 2 : appointmentUnavailable ? -1 : 1;

	return (
		<ol
			className="grid gap-3 rounded-xl border border-violet-100 bg-violet-50/40 p-4 sm:grid-cols-3 sm:gap-0 dark:border-violet-900/70 dark:bg-violet-950/10"
			aria-label="Progreso de tu cita"
		>
			{APPOINTMENT_PROGRESS_STEPS.map((step, index) => {
				const Icon = step.icon;
				const isCompleted =
					!appointmentUnavailable && index < activeIndex;
				const isCurrent =
					!appointmentUnavailable && index === activeIndex;
				const isFuture = !isCompleted && !isCurrent;
				const caption =
					step.id === "phone" && hasSavedAvailability && !payable
						? step.savedCaption
						: step.id === "payment" && payable
							? step.confirmedCaption
							: step.defaultCaption;

				return (
					<li
						key={step.id}
						className="relative flex items-center gap-3 sm:flex-col sm:items-center sm:gap-2 sm:text-center"
						aria-current={isCurrent ? "step" : undefined}
					>
						{index > 0 && (
							<span
								className="absolute -left-3 top-5 hidden h-px w-6 bg-violet-200 sm:block dark:bg-violet-800"
								aria-hidden="true"
							/>
						)}
						<span
							className={clsx(
								"relative z-10 flex size-10 shrink-0 items-center justify-center rounded-full",
								isCompleted &&
									"bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker",
								isCurrent &&
									"bg-emerald-100 text-emerald-800 ring-4 ring-emerald-50 dark:bg-emerald-300 dark:text-emerald-950 dark:ring-emerald-950/50",
								appointmentUnavailable &&
									index === 1 &&
									"bg-amber-100 text-amber-800 ring-4 ring-amber-50 dark:bg-amber-300 dark:text-amber-950 dark:ring-amber-950/40",
								isFuture &&
									"bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700",
							)}
						>
							<Icon className="size-5" aria-hidden="true" />
						</span>
						<span>
							<span
								className={clsx(
									"block text-sm font-semibold",
									isCurrent
										? "text-emerald-900 dark:text-emerald-100"
										: "text-zinc-900 dark:text-zinc-100",
									isFuture &&
										"text-zinc-700 dark:text-zinc-300",
								)}
							>
								{step.label}
							</span>
							<span
								className={clsx(
									"block text-xs",
									isCurrent
										? "text-emerald-700 dark:text-emerald-200"
										: "text-zinc-500 dark:text-zinc-400",
								)}
							>
								{appointmentUnavailable && index === 1
									? "Requiere atención"
									: caption}
							</span>
						</span>
					</li>
				);
			})}
		</ol>
	);
}

function AppointmentContactActions({
	isAvailable,
	telHref,
	phoneDisplay,
	whatsAppUrl,
	whatsAppDisplay,
	onCallClick,
	onRequestCall,
	isFormOpen,
}) {
	const whatsappButtonClasses =
		"inline-flex min-h-[78px] w-full items-center justify-center gap-3 rounded-xl border border-[#1ea952] bg-[#25D366] px-5 py-3 text-center font-semibold text-white shadow-sm transition-colors hover:bg-[#20bd5a] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 dark:border-[#25D366] dark:focus:ring-offset-zinc-900";
	const callLinkClasses =
		"inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl border border-famedic-dark bg-white px-4 py-3 text-center text-sm font-semibold text-famedic-dark transition-colors hover:bg-famedic-dark/[0.03] focus:outline-none focus:ring-2 focus:ring-famedic-dark focus:ring-offset-2 dark:border-famedic-lime dark:bg-zinc-900 dark:text-famedic-lime dark:hover:bg-famedic-lime/10 dark:focus:ring-famedic-lime dark:focus:ring-offset-zinc-900";

	return (
		<div>
			<p className="text-base font-semibold text-zinc-950 dark:text-white">
				Elige cómo contactarnos
			</p>
			<div className="mt-3 space-y-3">
				<a
					href={whatsAppUrl}
					target="_blank"
					rel="noopener noreferrer"
					className={whatsappButtonClasses}
					aria-label="Abrir WhatsApp oficial de citas en una nueva pestaña o aplicación"
				>
					<WhatsAppIcon className="size-6 shrink-0" />
					<span className="min-w-0">
						<span className="block text-sm">
							Continuar por WhatsApp
						</span>
						<span className="mt-0.5 block text-xs font-medium text-white/85">
							WhatsApp oficial de citas · {whatsAppDisplay}
						</span>
					</span>
				</a>
				<p className="text-center text-xs text-zinc-500 dark:text-zinc-400">
					{isAvailable
						? "Es la forma más rápida de confirmar con nuestro equipo."
						: "Puedes escribirnos ahora y te responderemos en el siguiente horario de atención."}
				</p>
				<a
					href={telHref}
					onClick={onCallClick}
					className={callLinkClasses}
				>
					<PhoneIcon className="size-5" aria-hidden="true" />
					Llamar al {phoneDisplay}
				</a>
				<p className="text-center text-sm text-zinc-600 dark:text-zinc-400">
					¿Prefieres que te llamemos?{" "}
					<button
						type="button"
						className="font-semibold text-famedic-dark underline decoration-famedic-dark/30 underline-offset-2 hover:text-famedic-darker focus:outline-none focus:ring-2 focus:ring-famedic-dark focus:ring-offset-2 dark:text-famedic-lime dark:focus:ring-famedic-lime dark:focus:ring-offset-zinc-900"
						onClick={onRequestCall}
						aria-expanded={isFormOpen}
						aria-controls="appointment-callback-form"
					>
						Solicitar llamada
					</button>
				</p>
			</div>
		</div>
	);
}
function ReceiveCallPanel({
	copy,
	receiveCallMode,
	setReceiveCallMode,
	dayOption,
	setDayOption,
	startTime,
	setStartTime,
	endTime,
	setEndTime,
	data,
	setData,
	errors,
	canSave,
	submittingAvailability,
	hasSavedAvailability,
	onSubmit,
	onCancel,
	formRef,
}) {
	return (
		<div
			id="appointment-callback-form"
			ref={formRef}
			className="border-t border-zinc-200 pt-5 dark:border-zinc-700"
		>
			<Subheading className="text-base">{copy.title}</Subheading>
			<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
				{copy.description}
			</p>

			<div className="mt-5 grid gap-3 sm:grid-cols-2">
				<OptionCard
					selected={receiveCallMode === "now"}
					label={copy.nowOptionLabel}
					onClick={() => setReceiveCallMode("now")}
				/>
				<OptionCard
					selected={receiveCallMode === "later"}
					label={copy.laterOptionLabel}
					onClick={() => setReceiveCallMode("later")}
				/>
			</div>

			<div className="mt-5 space-y-5">
				{receiveCallMode === "later" && (
					<>
						<Field>
							<Label>Día para recibir llamada</Label>
							<Select
								value={dayOption}
								onChange={(e) => setDayOption(e.target.value)}
							>
								<option value="today">Hoy</option>
								<option value="tomorrow">Mañana</option>
								<option value="day_after_tomorrow">
									Pasado mañana
								</option>
							</Select>
						</Field>
						<div className="grid gap-4 sm:grid-cols-2">
							<Field>
								<Label>Hora desde</Label>
								<Input
									type="time"
									value={startTime}
									onChange={(e) =>
										setStartTime(e.target.value)
									}
								/>
							</Field>
							<Field>
								<Label>Hora hasta</Label>
								<Input
									type="time"
									value={endTime}
									onChange={(e) => setEndTime(e.target.value)}
								/>
							</Field>
						</div>
					</>
				)}
				<Field>
					<Label>Comentarios adicionales</Label>
					<Textarea
						rows={3}
						value={data.patient_callback_comment}
						onChange={(e) =>
							setData("patient_callback_comment", e.target.value)
						}
						placeholder="Ej. puedo contestar después de las 6 p. m. entre semana."
					/>
					{errors.patient_callback_comment && (
						<ErrorMessage>
							{errors.patient_callback_comment}
						</ErrorMessage>
					)}
					{errors.callback_availability_starts_at && (
						<ErrorMessage>
							{errors.callback_availability_starts_at}
						</ErrorMessage>
					)}
					{errors.callback_availability_ends_at && (
						<ErrorMessage>
							{errors.callback_availability_ends_at}
						</ErrorMessage>
					)}
				</Field>
				<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
					<Button
						type="button"
						disabled={submittingAvailability || !canSave}
						onClick={onSubmit}
					>
						{getSaveButtonLabel(
							hasSavedAvailability,
							submittingAvailability,
						)}
					</Button>
					<Button type="button" plain onClick={onCancel}>
						Cancelar
					</Button>
					{!canSave && (
						<p className="text-xs text-zinc-500 sm:ml-auto dark:text-zinc-400">
							Indica un horario o escribe un comentario para
							continuar.
						</p>
					)}
				</div>
			</div>
		</div>
	);
}

function AppointmentCallbackSummary({
	hasSavedAvailability,
	formattedCallbackAvailabilityRange,
	patientCallbackComment,
	onModify,
}) {
	if (!hasSavedAvailability) {
		return null;
	}

	const detail =
		formattedCallbackAvailabilityRange || patientCallbackComment?.trim();

	return (
		<div className="flex flex-col gap-2 rounded-lg border border-zinc-200 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700">
			<div>
				<p className="font-semibold text-zinc-900 dark:text-zinc-100">
					Solicitud de llamada registrada
				</p>
				{detail && (
					<p className="mt-0.5 text-zinc-600 dark:text-zinc-400">
						{detail}
					</p>
				)}
			</div>
			<button
				type="button"
				className="self-start text-sm font-semibold text-famedic-dark underline decoration-famedic-dark/30 underline-offset-2 hover:text-famedic-darker dark:text-famedic-lime"
				onClick={onModify}
			>
				Modificar horario
			</button>
		</div>
	);
}

function AppointmentDetailsDisclosure({
	isOpen,
	onToggle,
	hasRequestSaved,
	requestSavedAtFormatted,
	patientFullName,
	hasSavedAvailability,
	callbackPreferenceSavedAtFormatted,
	formattedCallbackAvailabilityRange,
	patientCallbackComment,
	appointmentConfirmed,
	appointmentUnavailable,
	onUpdateAvailability,
}) {
	const detailsId = "appointment-request-details";
	const visualState = getAppointmentVisualState({
		hasSavedAvailability,
		appointmentConfirmed,
		appointmentUnavailable,
	});

	return (
		<div className="border-t border-zinc-200 pt-4 dark:border-zinc-700">
			<button
				type="button"
				className="flex w-full items-center justify-between gap-3 rounded-lg py-2 text-left text-sm font-semibold text-famedic-dark hover:text-famedic-darker focus:outline-none focus:ring-2 focus:ring-famedic-dark focus:ring-offset-2 dark:text-famedic-lime dark:focus:ring-famedic-lime dark:focus:ring-offset-zinc-900"
				onClick={onToggle}
				aria-expanded={isOpen}
				aria-controls={detailsId}
			>
				<span>Ver detalles de mi solicitud</span>
				<ChevronDownIcon
					className={clsx(
						"size-5 shrink-0 transition-transform",
						isOpen && "rotate-180",
					)}
					aria-hidden="true"
				/>
			</button>

			{isOpen && (
				<div id={detailsId} className="mt-4 space-y-4">
					<AppointmentProgressSteps
						hasRequestSaved={hasRequestSaved}
						hasSavedAvailability={hasSavedAvailability}
						appointmentConfirmed={appointmentConfirmed}
						appointmentUnavailable={appointmentUnavailable}
					/>

					<dl className="grid gap-3 rounded-lg border border-zinc-200 px-4 py-3 text-sm sm:grid-cols-2 dark:border-zinc-700">
						<div>
							<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
								Estado actual
							</dt>
							<dd className="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
								{visualState.indicator}
							</dd>
						</div>
						<div>
							<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
								Fecha de solicitud
							</dt>
							<dd className="mt-1 text-zinc-900 dark:text-zinc-100">
								{requestSavedAtFormatted ?? "Pendiente"}
							</dd>
						</div>
						<div>
							<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
								Paciente
							</dt>
							<dd className="mt-1 break-words text-zinc-900 dark:text-zinc-100">
								{patientFullName ?? "Sin nombre registrado"}
							</dd>
						</div>
						<div>
							<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
								Horario de llamada
							</dt>
							<dd className="mt-1 text-zinc-900 dark:text-zinc-100">
								{formattedCallbackAvailabilityRange ??
									"No has indicado un horario preferido"}
							</dd>
						</div>
						{patientCallbackComment?.trim() && (
							<div className="sm:col-span-2">
								<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
									Comentarios
								</dt>
								<dd className="mt-1 break-words text-zinc-900 dark:text-zinc-100">
									{patientCallbackComment.trim()}
								</dd>
							</div>
						)}
						<div>
							<dt className="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
								Última actualización
							</dt>
							<dd className="mt-1 text-zinc-900 dark:text-zinc-100">
								{callbackPreferenceSavedAtFormatted ??
									requestSavedAtFormatted ??
									"Pendiente"}
							</dd>
						</div>
					</dl>

					<button
						type="button"
						className="text-sm font-semibold text-famedic-dark underline decoration-famedic-dark/30 underline-offset-2 hover:text-famedic-darker dark:text-famedic-lime"
						onClick={onUpdateAvailability}
					>
						Modificar horario
					</button>
				</div>
			)}
		</div>
	);
}

export default function LaboratoryAppointmentStep({
	laboratoryAppointment,
	callbackPreferenceSavedAtFormatted,
	appointmentFirstFlow = false,
	appointmentConfirmed = false,
	appointmentUnavailable = false,
}) {
	const { famedicConcierge } = usePage().props;
	const [openPanel, setOpenPanel] = useState(null);
	const [minNowTick, setMinNowTick] = useState(() => minStartDatetimeLocal());
	const [availabilityTick, setAvailabilityTick] = useState(() => Date.now());
	const [receiveCallMode, setReceiveCallMode] = useState("now");
	const [dayOption, setDayOption] = useState("today");
	const defaultWindow = useMemo(() => getDefaultHourWindow(0), []);
	const [startTime, setStartTime] = useState(defaultWindow.startTime);
	const [endTime, setEndTime] = useState(defaultWindow.endTime);

	const hydratedFromServerKeyRef = useRef("");
	const callbackFormRef = useRef(null);

	const [submittingAvailability, setSubmittingAvailability] = useState(false);

	const { data, setData, errors, setError, clearErrors } = useForm({
		callback_availability_starts_at: toDatetimeLocal(
			laboratoryAppointment.callback_availability_starts_at,
		),
		callback_availability_ends_at: toDatetimeLocal(
			laboratoryAppointment.callback_availability_ends_at,
		),
		patient_callback_comment:
			laboratoryAppointment.patient_callback_comment ?? "",
	});

	useEffect(() => {
		const t = setInterval(
			() => setMinNowTick(minStartDatetimeLocal()),
			30000,
		);
		return () => clearInterval(t);
	}, []);

	useEffect(() => {
		const t = setInterval(() => setAvailabilityTick(Date.now()), 60000);
		return () => clearInterval(t);
	}, []);

	const conciergeAvailability = useMemo(
		() =>
			getConciergeAvailability(
				new Date(availabilityTick),
				famedicConcierge,
			),
		[availabilityTick, famedicConcierge],
	);

	const receiveCallCopy = useMemo(
		() => getReceiveCallCopy(conciergeAvailability.isAvailable),
		[conciergeAvailability.isAvailable],
	);

	useEffect(() => {
		const serverKey = [
			laboratoryAppointment.id,
			laboratoryAppointment.callback_availability_starts_at ?? "",
			laboratoryAppointment.callback_availability_ends_at ?? "",
			laboratoryAppointment.patient_callback_comment ?? "",
		].join("\0");

		if (hydratedFromServerKeyRef.current === serverKey) {
			return;
		}
		hydratedFromServerKeyRef.current = serverKey;

		const start = laboratoryAppointment.callback_availability_starts_at
			? new Date(laboratoryAppointment.callback_availability_starts_at)
			: null;
		const end = laboratoryAppointment.callback_availability_ends_at
			? new Date(laboratoryAppointment.callback_availability_ends_at)
			: null;

		if (!start || Number.isNaN(start.getTime())) {
			setData({
				callback_availability_starts_at: toDatetimeLocal(
					laboratoryAppointment.callback_availability_starts_at,
				),
				callback_availability_ends_at: toDatetimeLocal(
					laboratoryAppointment.callback_availability_ends_at,
				),
				patient_callback_comment:
					laboratoryAppointment.patient_callback_comment ?? "",
			});
			return;
		}

		const today = new Date();
		today.setHours(0, 0, 0, 0);
		const startDay = new Date(start);
		startDay.setHours(0, 0, 0, 0);
		const dayDiff = Math.round(
			(startDay.getTime() - today.getTime()) / (24 * 60 * 60 * 1000),
		);

		if (dayDiff >= 0 && dayDiff <= 2) {
			setReceiveCallMode("later");
			setDayOption(
				dayDiff === 0
					? "today"
					: dayDiff === 1
						? "tomorrow"
						: "day_after_tomorrow",
			);
			setStartTime(
				`${pad2(start.getHours())}:${pad2(start.getMinutes())}`,
			);
			if (end && !Number.isNaN(end.getTime())) {
				setEndTime(`${pad2(end.getHours())}:${pad2(end.getMinutes())}`);
			}
		} else if (laboratoryAppointment.has_left_callback_info) {
			setReceiveCallMode("now");
		}

		setData({
			callback_availability_starts_at: toDatetimeLocal(
				laboratoryAppointment.callback_availability_starts_at,
			),
			callback_availability_ends_at: toDatetimeLocal(
				laboratoryAppointment.callback_availability_ends_at,
			),
			patient_callback_comment:
				laboratoryAppointment.patient_callback_comment ?? "",
		});
	}, [laboratoryAppointment]);

	useEffect(() => {
		if (receiveCallMode !== "now") return;
		const window = getDefaultHourWindow(0);
		setData({
			callback_availability_starts_at: window.startAt,
			callback_availability_ends_at: window.endAt,
			patient_callback_comment: data.patient_callback_comment,
		});
	}, [receiveCallMode, minNowTick]);

	useEffect(() => {
		if (receiveCallMode !== "later") return;
		const dayOffset = toDayOffsetOption(dayOption);
		const base = getDefaultHourWindow(dayOffset);
		const [startHour = "00", startMinute = "00"] = startTime.split(":");
		const [endHour = "00", endMinute = "00"] = endTime.split(":");
		const startDate = new Date(base.startAt);
		const endDate = new Date(base.endAt);

		startDate.setHours(Number(startHour), Number(startMinute), 0, 0);
		endDate.setHours(Number(endHour), Number(endMinute), 0, 0);

		if (endDate <= startDate) {
			endDate.setTime(startDate.getTime() + 60 * 60 * 1000);
			setEndTime(
				`${pad2(endDate.getHours())}:${pad2(endDate.getMinutes())}`,
			);
		}

		setData({
			callback_availability_starts_at: toDatetimeLocal(startDate),
			callback_availability_ends_at: toDatetimeLocal(endDate),
			patient_callback_comment: data.patient_callback_comment,
		});
	}, [receiveCallMode, dayOption, startTime, endTime]);

	const telHref = famedicConcierge?.phoneTel
		? `tel:${famedicConcierge.phoneTel}`
		: "tel:5566515232";
	const phoneDisplay = famedicConcierge?.phoneDisplay ?? "(55) 6651 5232";
	const appointmentWhatsApp = famedicConcierge?.appointmentWhatsApp ?? {};
	const whatsAppUrl = appointmentWhatsApp.url;
	const whatsAppDisplay = appointmentWhatsApp.display;

	const onCallClick = (e) => {
		e.preventDefault();
		router.post(
			route("laboratory-appointments.phone-intent", {
				laboratory_brand: laboratoryAppointment.brand,
				laboratory_appointment: laboratoryAppointment.id,
			}),
			{},
			{
				preserveScroll: true,
				onFinish: () => {
					window.location.href = telHref;
				},
			},
		);
	};

	const minForStart = minNowTick;

	const startChosen = Boolean(
		data.callback_availability_starts_at &&
			new Date(data.callback_availability_starts_at).getTime() >=
				new Date(minForStart).getTime(),
	);

	const endValid = useMemo(() => {
		if (!data.callback_availability_ends_at || !startChosen) return false;
		const ds = new Date(data.callback_availability_starts_at);
		const de = new Date(data.callback_availability_ends_at);
		return de > ds && de >= new Date(minForStart);
	}, [
		data.callback_availability_starts_at,
		data.callback_availability_ends_at,
		startChosen,
		minForStart,
	]);

	const commentFilled = Boolean(data.patient_callback_comment?.trim());
	const windowComplete = startChosen && endValid;
	const canSave = commentFilled || windowComplete;

	const buildAvailabilityPayload = () => {
		const comment = data.patient_callback_comment?.trim() ?? "";

		if (comment && !windowComplete) {
			return {
				patient_callback_comment: comment,
				callback_availability_starts_at: null,
				callback_availability_ends_at: null,
			};
		}

		return {
			patient_callback_comment: comment || null,
			callback_availability_starts_at:
				data.callback_availability_starts_at || null,
			callback_availability_ends_at:
				data.callback_availability_ends_at || null,
		};
	};

	const submitAvailability = (e) => {
		e.preventDefault();
		if (submittingAvailability || !canSave) {
			return;
		}

		clearErrors();
		setSubmittingAvailability(true);

		router.patch(
			route("laboratory-appointments.callback-availability", {
				laboratory_brand: laboratoryAppointment.brand,
				laboratory_appointment: laboratoryAppointment.id,
			}),
			buildAvailabilityPayload(),
			{
				preserveScroll: true,
				onSuccess: () => {
					setOpenPanel(null);
					router.reload({
						only: [
							"pendingLaboratoryAppointment",
							"callbackPreferenceSavedAtFormatted",
						],
					});
				},
				onError: (submitErrors) => {
					Object.entries(submitErrors).forEach(([field, message]) => {
						setError(field, message);
					});
				},
				onFinish: () => setSubmittingAvailability(false),
			},
		);
	};

	const hasSavedCallbackPreference = Boolean(
		callbackPreferenceSavedAtFormatted,
	);
	const hasSavedAvailability = Boolean(
		laboratoryAppointment.has_left_callback_info ||
			hasSavedCallbackPreference,
	);
	const requestSavedAtFormatted =
		laboratoryAppointment.formatted_request_saved_at ?? null;
	const hasRequestSaved = Boolean(requestSavedAtFormatted);

	const openReceiveCallForm = () => {
		setOpenPanel("form");

		window.setTimeout(() => {
			if (!window.matchMedia("(max-width: 640px)").matches) {
				return;
			}

			callbackFormRef.current?.scrollIntoView({
				behavior: window.matchMedia("(prefers-reduced-motion: reduce)")
					.matches
					? "auto"
					: "smooth",
				block: "start",
			});
		}, 0);
	};

	const closePanels = () => setOpenPanel(null);

	const toggleDetails = () => {
		setOpenPanel((current) => (current === "details" ? null : "details"));
	};

	const appointmentFirstUnavailableMessage =
		appointmentFirstFlow && appointmentUnavailable
			? "Tu cita ya no está disponible para completar el pago. Puedes actualizar tu disponibilidad o solicitar que te llamemos para gestionar una nueva cita."
			: null;

	return (
		<CheckoutWizardStep title="Cita" description={null}>
			<div className="space-y-5">
				<AppointmentHeaderRow
					isAvailable={conciergeAvailability.isAvailable}
				/>

				{!conciergeAvailability.isAvailable && (
					<div className="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
						<p>
							Solicita una llamada y nuestro equipo te contactará
							en el siguiente horario de atención para confirmar
							tu cita.
						</p>
						{conciergeAvailability.nextAvailableText && (
							<p className="font-medium text-zinc-800 dark:text-zinc-100">
								Próximo horario:{" "}
								{conciergeAvailability.nextAvailableText}
							</p>
						)}
					</div>
				)}

				{appointmentFirstUnavailableMessage && (
					<p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-100 dark:ring-amber-800">
						{appointmentFirstUnavailableMessage}
					</p>
				)}

				<AppointmentStatusSummary
					hasSavedAvailability={hasSavedAvailability}
					appointmentConfirmed={appointmentConfirmed}
					appointmentUnavailable={appointmentUnavailable}
				/>

				<AppointmentProgressSteps
					hasRequestSaved={hasRequestSaved}
					hasSavedAvailability={hasSavedAvailability}
					appointmentConfirmed={appointmentConfirmed}
					appointmentUnavailable={appointmentUnavailable}
				/>

				<AppointmentContactActions
					isAvailable={conciergeAvailability.isAvailable}
					telHref={telHref}
					phoneDisplay={phoneDisplay}
					whatsAppUrl={whatsAppUrl}
					whatsAppDisplay={whatsAppDisplay}
					onCallClick={onCallClick}
					onRequestCall={openReceiveCallForm}
					isFormOpen={openPanel === "form"}
				/>

				<AppointmentCallbackSummary
					hasSavedAvailability={hasSavedAvailability}
					formattedCallbackAvailabilityRange={
						laboratoryAppointment.formatted_callback_availability_range
					}
					patientCallbackComment={
						laboratoryAppointment.patient_callback_comment
					}
					onModify={openReceiveCallForm}
				/>

				{openPanel === "form" && (
					<ReceiveCallPanel
						copy={receiveCallCopy}
						receiveCallMode={receiveCallMode}
						setReceiveCallMode={setReceiveCallMode}
						dayOption={dayOption}
						setDayOption={setDayOption}
						startTime={startTime}
						setStartTime={setStartTime}
						endTime={endTime}
						setEndTime={setEndTime}
						data={data}
						setData={setData}
						errors={errors}
						canSave={canSave}
						submittingAvailability={submittingAvailability}
						hasSavedAvailability={hasSavedAvailability}
						onSubmit={submitAvailability}
						onCancel={closePanels}
						formRef={callbackFormRef}
					/>
				)}

				<AppointmentDetailsDisclosure
					isOpen={openPanel === "details"}
					onToggle={toggleDetails}
					hasRequestSaved={hasRequestSaved}
					requestSavedAtFormatted={requestSavedAtFormatted}
					patientFullName={laboratoryAppointment.patient_full_name}
					hasSavedAvailability={hasSavedAvailability}
					callbackPreferenceSavedAtFormatted={
						callbackPreferenceSavedAtFormatted
					}
					formattedCallbackAvailabilityRange={
						laboratoryAppointment.formatted_callback_availability_range
					}
					patientCallbackComment={
						laboratoryAppointment.patient_callback_comment
					}
					appointmentConfirmed={appointmentConfirmed}
					appointmentUnavailable={appointmentUnavailable}
					onUpdateAvailability={openReceiveCallForm}
				/>
			</div>

			<p className="mt-4 flex items-center justify-center gap-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
				<LockClosedIcon
					className="size-4 shrink-0"
					aria-hidden="true"
				/>
				<span>
					Tus datos están guardados. No realizaremos ningún cargo
					todavía.
				</span>
			</p>
		</CheckoutWizardStep>
	);
}
