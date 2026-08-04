import { Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { CheckIcon, PhoneIcon } from "@heroicons/react/20/solid";
import {
	CalendarDaysIcon,
	ClipboardDocumentListIcon,
} from "@heroicons/react/24/outline";
import {
	Field,
	Label,
	ErrorMessage,
} from "@/Components/Catalyst/fieldset";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import {
	TabGroup,
	TabList,
	Tab,
	TabPanels,
	TabPanel,
} from "@/Components/Catalyst/tabs";
import { DevicePhoneMobileIcon } from "@heroicons/react/16/solid";
import { useState, useEffect, useMemo, useRef } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import clsx from "clsx";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import CheckoutSaveSuccessAlert from "@/Components/Checkout/CheckoutSaveSuccessAlert";
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

const TAB_CALL_NOW = 0;
const TAB_RECEIVE_CALL = 1;
const TAB_TRACKING = 2;

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

function TabPanelCard({ children, className }) {
	return (
		<div
			className={clsx(
				"rounded-xl border border-zinc-200/80 bg-white p-4 text-zinc-900 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-100 sm:p-5",
				className,
			)}
		>
			{children}
		</div>
	);
}

function ConciergeStatusBadge({ isAvailable }) {
	const label = isAvailable
		? "Equipo de atención en línea"
		: "Equipo fuera de horario";

	return (
		<div
			className="inline-flex items-center gap-2 rounded-full border border-zinc-200/80 bg-zinc-50 px-3 py-1 dark:border-zinc-700 dark:bg-zinc-800/60"
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
			<span className="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
				{label}
			</span>
		</div>
	);
}

function AppointmentTabButton({ selected, icon: Icon, label }) {
	return (
		<span
			className={clsx(
				"flex min-h-[44px] flex-col items-center justify-center gap-0.5 rounded-lg px-2 py-2 text-sm font-semibold transition-colors sm:flex-row sm:gap-2 sm:py-1.5",
				selected
					? "bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-900/5 dark:bg-zinc-900 dark:text-white dark:ring-white/10"
					: "text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white",
			)}
		>
			<Icon className="size-5 shrink-0" aria-hidden="true" />
			{label}
		</span>
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
	{ id: "request", label: "Solicitud recibida" },
	{ id: "team", label: "Equipo por confirmar" },
	{ id: "confirmed", label: "Cita confirmada" },
	{ id: "payment", label: "Lista para pagar" },
];

function AppointmentProgressSteps({ hasRequestSaved }) {
	const activeIndex = hasRequestSaved ? 1 : 0;

	return (
		<ol className="space-y-2" aria-label="Progreso de tu cita">
			{APPOINTMENT_PROGRESS_STEPS.map((step, index) => {
				const isCompleted = index < activeIndex;
				const isCurrent = index === activeIndex;

				return (
					<li
						key={step.id}
						className={clsx(
							"flex items-center gap-3 rounded-lg px-3 py-2 text-sm",
							isCompleted &&
								"bg-green-50 text-green-900 dark:bg-green-950/30 dark:text-green-200",
							isCurrent &&
								"bg-violet-50 text-violet-900 ring-1 ring-violet-200 dark:bg-violet-950/30 dark:text-violet-100 dark:ring-violet-800",
							!isCompleted &&
								!isCurrent &&
								"text-zinc-500 dark:text-zinc-400",
						)}
						aria-current={isCurrent ? "step" : undefined}
					>
						<span
							className={clsx(
								"flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold",
								isCompleted && "bg-green-600 text-white",
								isCurrent &&
									"bg-violet-700 text-white dark:bg-violet-600",
								!isCompleted &&
									!isCurrent &&
									"border border-zinc-300 bg-zinc-100 text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800",
							)}
						>
							{isCompleted ? (
								<CheckIcon className="size-4" aria-hidden="true" />
							) : (
								index + 1
							)}
						</span>
						<span className="font-medium">{step.label}</span>
						{isCurrent && (
							<span className="ml-auto text-xs font-normal opacity-80">
								En curso
							</span>
						)}
					</li>
				);
			})}
		</ol>
	);
}

function CallNowPanel({
	isAvailable,
	nextAvailableText,
	scheduleText,
	userPhone,
	telHref,
	onCallClick,
	onRequestCall,
}) {
	return (
		<TabPanelCard className="mx-auto max-w-md text-center">
			<div className="flex justify-center">
				<PhoneIcon
					className={clsx(
						"size-14",
						isAvailable
							? "fill-green-600 dark:fill-green-400"
							: "fill-amber-500/80 dark:fill-amber-400/80",
					)}
					aria-hidden="true"
				/>
			</div>

			<div className="mt-4">
				<ConciergeStatusBadge isAvailable={isAvailable} />
			</div>

			{isAvailable ? (
				<>
					<Text className="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
						Podemos ayudarte ahora a confirmar tu cita.
					</Text>
					<div className="mt-5">
						<a href={telHref} onClick={onCallClick} className="inline-block">
							<Button type="button">
								<PhoneIcon aria-hidden="true" />
								Llamar al (55) 6651 5232
							</Button>
						</a>
					</div>
					{userPhone && (
						<Text className="mt-4 flex flex-wrap items-center justify-center gap-1 text-sm text-zinc-600 dark:text-zinc-300">
							<span>También podemos llamarte al</span>
							<span className="inline-flex items-center gap-1 font-medium text-zinc-800 dark:text-zinc-100">
								<DevicePhoneMobileIcon
									className="size-4"
									aria-hidden="true"
								/>
								{userPhone}.
							</span>
						</Text>
					)}
					<button
						type="button"
						className="mt-5 text-sm font-medium text-sky-600 underline decoration-sky-600/30 underline-offset-2 hover:text-sky-700 dark:text-sky-400"
						onClick={onRequestCall}
					>
						Prefiero que me llamen
					</button>
				</>
			) : (
				<>
					<div className="mt-4 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
						<p>Ahora no estamos disponibles por teléfono.</p>
						<p>
							Puedes dejar tu solicitud y te llamaremos en el siguiente
							horario disponible.
						</p>
					</div>
					{nextAvailableText && (
						<Text className="mt-4 text-sm font-medium text-zinc-800 dark:text-zinc-100">
							Próximo horario: {nextAvailableText}
						</Text>
					)}
					<ul className="mt-4 space-y-0.5 text-left text-xs text-zinc-500 dark:text-zinc-400">
						{scheduleText.map((line) => (
							<li key={line}>{line}</li>
						))}
					</ul>
					<div className="mt-5">
						<Button type="button" onClick={onRequestCall}>
							Solicitar llamada
						</Button>
					</div>
				</>
			)}
		</TabPanelCard>
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
}) {
	return (
		<TabPanelCard>
			<Subheading className="text-center text-base">{copy.title}</Subheading>
			<Text className="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
				{copy.description}
			</Text>

			<div className="mt-5 grid gap-3">
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
								<option value="day_after_tomorrow">Pasado mañana</option>
							</Select>
						</Field>
						<div className="grid gap-4 sm:grid-cols-2">
							<Field>
								<Label>Hora desde</Label>
								<Input
									type="time"
									value={startTime}
									onChange={(e) => setStartTime(e.target.value)}
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
						<ErrorMessage>{errors.patient_callback_comment}</ErrorMessage>
					)}
					{errors.callback_availability_starts_at && (
						<ErrorMessage>
							{errors.callback_availability_starts_at}
						</ErrorMessage>
					)}
					{errors.callback_availability_ends_at && (
						<ErrorMessage>{errors.callback_availability_ends_at}</ErrorMessage>
					)}
				</Field>
				<div className="flex flex-col items-center gap-2">
					<Button
						type="button"
						disabled={submittingAvailability || !canSave}
						onClick={onSubmit}
					>
						{getSaveButtonLabel(hasSavedAvailability, submittingAvailability)}
					</Button>
					{!canSave && (
						<Text className="text-center text-xs text-zinc-500 dark:text-zinc-400">
							Indica un horario o escribe un comentario para continuar.
						</Text>
					)}
				</div>
			</div>
		</TabPanelCard>
	);
}

function AppointmentStatusPanel({
	hasRequestSaved,
	requestSavedAtFormatted,
	patientFullName,
	hasSavedAvailability,
	callbackPreferenceSavedAtFormatted,
	formattedCallbackAvailabilityRange,
	patientCallbackComment,
	onAddContactSchedule,
	onUpdateAvailability,
}) {
	return (
		<TabPanelCard>
			<Subheading className="text-center text-base">
				Estado de tu cita
			</Subheading>
			<Text className="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
				Consulta el avance de tu solicitud y los datos que registraste.
			</Text>

			<div className="mt-5">
				<AppointmentProgressSteps hasRequestSaved={hasRequestSaved} />
			</div>

			<div className="mt-5 space-y-4">
				{hasRequestSaved ? (
					<CheckoutSaveSuccessAlert
						message="Recibimos tu solicitud correctamente."
						hint={
							<>
								Un asesor del equipo de atención confirmará fecha, horario
								y sucursal.
								<br />
								<Strong>Solicitud:</Strong> el {requestSavedAtFormatted}
								{patientFullName && (
									<>
										<br />
										<Strong>Paciente:</Strong> {patientFullName}
									</>
								)}
							</>
						}
					/>
				) : (
					<div className="rounded-lg border border-dashed border-zinc-300 px-4 py-3 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p>Aún no hay solicitud registrada.</p>
						<p className="mt-1">
							Usa «Llamar ahora» o «Que me llamen» para iniciar.
						</p>
					</div>
				)}

				{hasSavedAvailability ? (
					<CheckoutSaveSuccessAlert
						message="Tu horario de contacto quedó registrado."
						hint={
							<>
								{callbackPreferenceSavedAtFormatted && (
									<>
										<Strong>Actualización:</Strong> el{" "}
										{callbackPreferenceSavedAtFormatted}
										<br />
									</>
								)}
								{formattedCallbackAvailabilityRange && (
									<>
										<Strong>Horario:</Strong>{" "}
										{formattedCallbackAvailabilityRange}
										<br />
									</>
								)}
								{patientCallbackComment?.trim() && (
									<>
										<Strong>Comentarios:</Strong>{" "}
										{patientCallbackComment.trim()}
									</>
								)}
							</>
						}
					/>
				) : (
					<div className="rounded-lg bg-zinc-50 px-4 py-3 text-center text-sm dark:bg-zinc-800/50">
						<p className="text-zinc-600 dark:text-zinc-300">
							No has indicado un horario preferido para recibir llamada.
						</p>
						<button
							type="button"
							className="mt-2 font-medium text-sky-600 underline underline-offset-2 hover:text-sky-700 dark:text-sky-400"
							onClick={onAddContactSchedule}
						>
							Agregar horario de contacto
						</button>
					</div>
				)}

				{(hasRequestSaved || hasSavedAvailability) && (
					<p className="text-center text-xs text-zinc-500 dark:text-zinc-400">
						¿Necesitas cambiar algo?{" "}
						<button
							type="button"
							className="font-medium text-sky-600 underline underline-offset-2 dark:text-sky-400"
							onClick={onUpdateAvailability}
						>
							Actualizar en «Que me llamen»
						</button>
					</p>
				)}
			</div>
		</TabPanelCard>
	);
}

export default function LaboratoryAppointmentStep({
	laboratoryAppointment,
	callbackPreferenceSavedAtFormatted,
}) {
	const { auth } = usePage().props;
	const [tabIndex, setTabIndex] = useState(0);
	const [minNowTick, setMinNowTick] = useState(() => minStartDatetimeLocal());
	const [availabilityTick, setAvailabilityTick] = useState(() => Date.now());
	const [receiveCallMode, setReceiveCallMode] = useState("now");
	const [dayOption, setDayOption] = useState("today");
	const defaultWindow = useMemo(() => getDefaultHourWindow(0), []);
	const [startTime, setStartTime] = useState(defaultWindow.startTime);
	const [endTime, setEndTime] = useState(defaultWindow.endTime);

	const hydratedFromServerKeyRef = useRef("");

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
		const t = setInterval(() => setMinNowTick(minStartDatetimeLocal()), 30000);
		return () => clearInterval(t);
	}, []);

	useEffect(() => {
		const t = setInterval(() => setAvailabilityTick(Date.now()), 60000);
		return () => clearInterval(t);
	}, []);

	const conciergeAvailability = useMemo(
		() => getConciergeAvailability(new Date(availabilityTick)),
		[availabilityTick],
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
			setStartTime(`${pad2(start.getHours())}:${pad2(start.getMinutes())}`);
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
			setEndTime(`${pad2(endDate.getHours())}:${pad2(endDate.getMinutes())}`);
		}

		setData({
			callback_availability_starts_at: toDatetimeLocal(startDate),
			callback_availability_ends_at: toDatetimeLocal(endDate),
			patient_callback_comment: data.patient_callback_comment,
		});
	}, [receiveCallMode, dayOption, startTime, endTime]);

	useEffect(() => {
		const hasSavedCallbackProgress =
			Boolean(callbackPreferenceSavedAtFormatted) ||
			Boolean(laboratoryAppointment.has_left_callback_info);

		setTabIndex(hasSavedCallbackProgress ? TAB_TRACKING : TAB_CALL_NOW);
	}, [
		laboratoryAppointment.id,
		laboratoryAppointment.has_left_callback_info,
		callbackPreferenceSavedAtFormatted,
	]);

	const telHref = "tel:5566515232";

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
					setTabIndex(TAB_TRACKING);
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

	const hasSavedCallbackPreference = Boolean(callbackPreferenceSavedAtFormatted);
	const hasSavedAvailability = Boolean(
		laboratoryAppointment.has_left_callback_info || hasSavedCallbackPreference,
	);
	const requestSavedAtFormatted =
		laboratoryAppointment.formatted_request_saved_at ?? null;
	const hasRequestSaved = Boolean(requestSavedAtFormatted);

	const goToReceiveCallTab = () => setTabIndex(TAB_RECEIVE_CALL);

	return (
		<CheckoutWizardStep
			title="Agenda tu cita con ayuda de nuestro equipo"
			description="Elige cómo quieres que confirmemos fecha, horario y sucursal."
		>
			<TabGroup selectedIndex={tabIndex} onChange={setTabIndex}>
				<TabList className="grid grid-cols-3 gap-2 rounded-xl bg-zinc-100 p-1.5 dark:bg-zinc-800/80">
					<Tab className="rounded-lg outline-none transition-colors">
						{(selected) => (
							<AppointmentTabButton
								selected={selected}
								icon={PhoneIcon}
								label="Llamar ahora"
							/>
						)}
					</Tab>
					<Tab className="rounded-lg outline-none transition-colors">
						{(selected) => (
							<AppointmentTabButton
								selected={selected}
								icon={CalendarDaysIcon}
								label="Que me llamen"
							/>
						)}
					</Tab>
					<Tab className="rounded-lg outline-none transition-colors">
						{(selected) => (
							<AppointmentTabButton
								selected={selected}
								icon={ClipboardDocumentListIcon}
								label="Estado de mi cita"
							/>
						)}
					</Tab>
				</TabList>

				<TabPanels className="mt-5">
					<TabPanel className="outline-none">
						<CallNowPanel
							isAvailable={conciergeAvailability.isAvailable}
							nextAvailableText={conciergeAvailability.nextAvailableText}
							scheduleText={conciergeAvailability.scheduleText}
							userPhone={auth?.user?.phone}
							telHref={telHref}
							onCallClick={onCallClick}
							onRequestCall={goToReceiveCallTab}
						/>
					</TabPanel>

					<TabPanel className="outline-none">
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
						/>
					</TabPanel>

					<TabPanel className="outline-none">
						<AppointmentStatusPanel
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
							onAddContactSchedule={goToReceiveCallTab}
							onUpdateAvailability={goToReceiveCallTab}
						/>
					</TabPanel>
				</TabPanels>
			</TabGroup>

			<Text className="mt-5 text-center text-sm text-zinc-600 dark:text-slate-400">
				Cuando el equipo de atención confirme tu cita, avanzaremos
				automáticamente al resumen para pagar.
			</Text>
		</CheckoutWizardStep>
	);
}
