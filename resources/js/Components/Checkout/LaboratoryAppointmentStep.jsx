import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { WhatsAppIcon } from "@/Components/Checkout/CheckoutWhatsAppHelp";
import { checkoutSelectedStorePresentation } from "@/lib/laboratoryCompatibleStores";
import {
	CalendarDaysIcon,
	CheckIcon,
	InformationCircleIcon,
	LockClosedIcon,
	MapPinIcon,
	PhoneIcon,
} from "@heroicons/react/20/solid";
import Card from "@/Components/Card";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { useState, useEffect, useMemo, useRef } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import clsx from "clsx";
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
	appointmentFirstFlow,
}) {
	if (appointmentConfirmed) {
		return {
			title: "Tu cita está confirmada",
			description: appointmentFirstFlow
				? "Tu cita quedó agendada. Ya puedes continuar con el pago."
				: "Tu cita quedó agendada. Revisa y confirma tu compra en el siguiente paso.",
			badge: "Confirmada",
			badgeTone: "success",
		};
	}

	if (appointmentUnavailable) {
		return {
			title: "Cita no disponible",
			description:
				"Necesitamos actualizar tu disponibilidad para gestionar una nueva cita por teléfono.",
			badge: "Requiere atención",
			badgeTone: "warning",
		};
	}

	if (hasSavedAvailability) {
		return {
			title: "Cita pendiente de coordinación",
			description:
				"Nuestro equipo de Concierge te ayudará a coordinar la sucursal, la fecha y el horario.",
			badge: "Esperando coordinación",
			badgeTone: "pending",
		};
	}

	return {
		title: "Cita pendiente de coordinación",
		description:
			"Nuestro equipo de Concierge te ayudará a coordinar la sucursal, la fecha y el horario.",
		badge: "Contacto pendiente",
		badgeTone: "pending",
	};
}

const BADGE_TONE_CLASSES = {
	success:
		"bg-emerald-50 text-emerald-800 ring-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-100 dark:ring-emerald-800/70",
	warning:
		"bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-100 dark:ring-amber-800/70",
	pending:
		"bg-sky-50 text-sky-900 ring-sky-200 dark:bg-sky-950/30 dark:text-sky-100 dark:ring-sky-800/70",
};

function AppointmentStepHeader() {
	return (
		<div className="space-y-2">
			<Subheading className="text-lg dark:!text-famedic-lime">
				¿Cómo será tu cita?
			</Subheading>
			<Text className="text-sm text-zinc-600 dark:text-zinc-400">
				Algunos estudios requieren una cita previa. Coordinaremos contigo
				la sucursal, la fecha y el horario.
			</Text>
		</div>
	);
}

function AppointmentStudiesNotice({ studyItems = [] }) {
	if (!studyItems.length) {
		return null;
	}

	return (
		<div className="rounded-lg border border-sky-200/80 bg-sky-50/60 px-4 py-3 dark:border-sky-900/50 dark:bg-sky-950/20">
			<Text className="text-sm font-medium text-sky-950 dark:text-sky-100">
				Estos estudios requieren una cita previa
			</Text>
			<ul className="mt-2 space-y-1.5" role="list">
				{studyItems.map((item, index) => (
					<li
						key={`${item.heading}-${index}`}
						className="flex items-start gap-2 text-sm text-sky-900/90 dark:text-sky-100/90"
					>
						<span
							className="mt-2 size-1.5 shrink-0 rounded-full bg-sky-600 dark:bg-sky-300"
							aria-hidden="true"
						/>
						<span className="min-w-0 break-words">{item.heading}</span>
					</li>
				))}
			</ul>
		</div>
	);
}

function AppointmentStatusSummary({
	hasSavedAvailability,
	appointmentConfirmed,
	appointmentUnavailable,
	appointmentFirstFlow,
}) {
	const visualState = getAppointmentVisualState({
		hasSavedAvailability,
		appointmentConfirmed,
		appointmentUnavailable,
		appointmentFirstFlow,
	});

	return (
		<div className="rounded-lg border border-zinc-200 bg-zinc-50/60 px-4 py-4 dark:border-zinc-700 dark:bg-zinc-800/40">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div className="min-w-0 flex-1 space-y-2">
					<h3 className="text-lg font-semibold text-famedic-dark dark:text-white">
						{visualState.title}
					</h3>
					<p className="text-sm leading-6 text-zinc-600 dark:text-zinc-400">
						{visualState.description}
					</p>
				</div>
				{visualState.badge && (
					<span
						className={clsx(
							"inline-flex shrink-0 items-center rounded-full px-3 py-1 text-xs font-semibold ring-1",
							BADGE_TONE_CLASSES[visualState.badgeTone],
						)}
					>
						{visualState.badge}
					</span>
				)}
			</div>
		</div>
	);
}

function AppointmentConfirmedStorePanel({ store }) {
	if (!store?.name) {
		return null;
	}

	return (
		<div className="rounded-lg border border-emerald-200 bg-emerald-50/50 px-4 py-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
			<div className="flex items-start gap-3">
				<MapPinIcon
					className="mt-0.5 size-5 shrink-0 text-emerald-700 dark:text-emerald-300"
					aria-hidden="true"
				/>
				<div className="min-w-0 space-y-1">
					<Badge color="green">Sucursal confirmada</Badge>
					<p className="text-base font-semibold uppercase tracking-wide text-emerald-950 dark:text-emerald-50">
						{store.name}
					</p>
					{store.address && (
						<Text className="text-sm text-emerald-900/80 dark:text-emerald-100/80">
							{store.address}
						</Text>
					)}
				</div>
			</div>
		</div>
	);
}

function AppointmentPreferredStorePanel({ selection }) {
	const presentation = checkoutSelectedStorePresentation(selection);

	if (presentation.state !== "valid" || !presentation.storeName) {
		return null;
	}

	return (
		<div className="rounded-lg border border-zinc-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-slate-900/60">
			<div className="flex items-start gap-3">
				<MapPinIcon
					className="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400"
					aria-hidden="true"
				/>
				<div className="min-w-0 space-y-1">
					<Badge color="zinc">Sucursal preferida</Badge>
					<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
						{presentation.storeName}
					</p>
					{presentation.message && (
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							{presentation.message}
						</Text>
					)}
					<Text className="text-xs text-zinc-500 dark:text-zinc-500">
						Es una preferencia opcional. La sucursal confirmada puede
						ser diferente.
					</Text>
				</div>
			</div>
		</div>
	);
}

function AppointmentSchedulePanel({
	appointmentConfirmed,
	formattedAppointmentDate,
	formattedCallbackAvailabilityRange,
	hasSavedAvailability,
}) {
	const isConfirmed = Boolean(
		appointmentConfirmed && formattedAppointmentDate,
	);

	return (
		<div className="rounded-lg border border-zinc-200 px-4 py-4 dark:border-zinc-700">
			<div className="flex items-start gap-3">
				<CalendarDaysIcon
					className="mt-0.5 size-5 shrink-0 text-famedic-dark dark:text-famedic-lime"
					aria-hidden="true"
				/>
				<div className="min-w-0 space-y-2">
					<Subheading className="text-base">
						Fecha y horario
					</Subheading>
					{isConfirmed ? (
						<>
							<Badge color="green">Confirmados</Badge>
							<Text className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
								{formattedAppointmentDate}
							</Text>
						</>
					) : (
						<>
							<Badge color="sky">Por confirmar</Badge>
							<Text className="text-sm text-zinc-600 dark:text-zinc-400">
								Fecha y horario por confirmar. Nuestro equipo te
								contactará para acordarlos.
							</Text>
							{hasSavedAvailability &&
								formattedCallbackAvailabilityRange && (
									<div className="rounded-md bg-zinc-50 px-3 py-2 dark:bg-zinc-800/60">
										<Text className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
											Disponibilidad indicada
										</Text>
										<Text className="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
											{formattedCallbackAvailabilityRange}
										</Text>
									</div>
								)}
						</>
					)}
				</div>
			</div>
		</div>
	);
}

function AppointmentPendingCard({ children }) {
	return (
		<div className="rounded-lg border border-sky-200/80 bg-sky-50/40 px-4 py-5 dark:border-sky-900/50 dark:bg-sky-950/15">
			<div className="mb-4 flex items-start gap-3">
				<CalendarDaysIcon
					className="size-5 shrink-0 text-sky-700 dark:text-sky-300"
					aria-hidden="true"
				/>
				<div className="space-y-1">
					<Subheading className="text-base">
						Coordina tu cita con Concierge
					</Subheading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Puedes comunicarte con nuestro equipo para acordar la
						fecha y el horario.
					</Text>
				</div>
			</div>
			{children}
		</div>
	);
}

function AppointmentPaymentSafetyNotice() {
	return (
		<div className="flex items-start gap-3 rounded-xl border border-indigo-100 bg-indigo-50/70 px-4 py-3.5 text-sm text-indigo-900 dark:border-indigo-900/70 dark:bg-indigo-950/20 dark:text-indigo-100">
			<InformationCircleIcon
				className="mt-0.5 size-5 shrink-0 text-indigo-600 dark:text-indigo-300"
				aria-hidden="true"
			/>
			<p className="leading-6">
				No se efectuará ningún cargo hasta que tu cita sea confirmada y
				tú lo autorices.
			</p>
		</div>
	);
}

function AppointmentPaymentAuthorizationNote() {
	return (
		<div className="border-t border-zinc-200 pt-5 dark:border-zinc-700">
			<div className="flex items-start gap-3 text-sm text-zinc-600 dark:text-zinc-400">
				<span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-famedic-dark ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-famedic-lime dark:ring-zinc-700">
					<LockClosedIcon className="size-4" aria-hidden="true" />
				</span>
				<div className="space-y-1">
					<p className="font-semibold text-zinc-900 dark:text-zinc-100">
						El pago se habilitará más adelante, solo con tu
						autorización.
					</p>
					<p>
						Con la cita confirmada podrás autorizar el pago o
						cancelar la operación.
					</p>
				</div>
			</div>
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

function AppointmentContactActions({
	telHref,
	phoneDisplay,
	whatsAppUrl,
	whatsAppDisplay,
	onWhatsAppClick,
	onCallClick,
	onRequestCall,
	isFormOpen,
}) {
	const whatsappButtonClasses =
		"inline-flex min-h-[76px] w-full items-center justify-center gap-3 rounded-xl border border-[#1ea952] bg-[#25D366] px-5 py-3 text-center font-semibold text-white shadow-sm transition-colors hover:bg-[#20bd5a] focus:outline-none focus:ring-2 focus:ring-[#25D366] focus:ring-offset-2 dark:border-[#25D366] dark:focus:ring-offset-zinc-900";
	const callLinkClasses =
		"inline-flex min-h-[54px] w-full items-center justify-center gap-2 rounded-xl border border-famedic-dark bg-white px-4 py-3 text-center text-sm font-semibold text-famedic-dark transition-colors hover:bg-famedic-dark/[0.03] focus:outline-none focus:ring-2 focus:ring-famedic-dark focus:ring-offset-2 dark:border-famedic-lime dark:bg-zinc-900 dark:text-famedic-lime dark:hover:bg-famedic-lime/10 dark:focus:ring-famedic-lime dark:focus:ring-offset-zinc-900";

	return (
		<div>
			<div className="space-y-3">
				<a
					href={whatsAppUrl}
					target="_blank"
					rel="noopener noreferrer"
					className={whatsappButtonClasses}
					onClick={onWhatsAppClick}
					aria-label="Abrir WhatsApp oficial de citas en una nueva pestaña o aplicación"
				>
					<WhatsAppIcon className="size-6 shrink-0" />
					<span className="min-w-0">
						<span className="block text-sm">
							Crear cita por WhatsApp
						</span>
						<span className="mt-0.5 block text-xs font-medium text-white/85">
							WhatsApp oficial de citas · {whatsAppDisplay}
						</span>
					</span>
				</a>
				<a
					href={telHref}
					onClick={onCallClick}
					className={callLinkClasses}
				>
					<PhoneIcon className="size-5" aria-hidden="true" />
					Llamar ahora al {phoneDisplay}
				</a>
				<button
					type="button"
					className="mx-auto flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-center text-sm font-semibold text-famedic-dark transition-colors hover:bg-famedic-dark/[0.04] focus:outline-none focus:ring-2 focus:ring-famedic-dark focus:ring-offset-2 sm:w-auto dark:text-famedic-lime dark:hover:bg-famedic-lime/10 dark:focus:ring-famedic-lime dark:focus:ring-offset-zinc-900"
					onClick={onRequestCall}
					aria-expanded={isFormOpen}
					aria-controls="appointment-callback-form"
				>
					<PhoneIcon className="size-4" aria-hidden="true" />
					Prefiero que me llamen
				</button>
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

export default function LaboratoryAppointmentStep({
	laboratoryAppointment,
	callbackPreferenceSavedAtFormatted,
	appointmentFirstFlow = false,
	appointmentConfirmed = false,
	appointmentUnavailable = false,
	studyItemsRequiringAppointment = [],
	selectedLaboratoryStore = null,
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

	const trackContactIntent = (channel) => {
		if (!laboratoryAppointment?.id || !laboratoryAppointment?.brand) {
			return;
		}

		const search = new URLSearchParams(window.location.search);
		const token = document
			.querySelector('meta[name="csrf-token"]')
			?.getAttribute("content");

		fetch(
			route("laboratory-appointments.phone-intent", {
				laboratory_brand: laboratoryAppointment.brand,
				laboratory_appointment: laboratoryAppointment.id,
			}),
			{
				method: "POST",
				credentials: "same-origin",
				keepalive: true,
				headers: {
					Accept: "application/json",
					"Content-Type": "application/json",
					"X-Requested-With": "XMLHttpRequest",
					...(token ? { "X-CSRF-TOKEN": token } : {}),
				},
				body: JSON.stringify({
					channel,
					context: "laboratory_checkout",
					step: "appointment",
					address_id: search.get("address"),
					contact_id: search.get("contact"),
					current_url: window.location.href,
				}),
			},
		).catch(() => {});
	};

	const onWhatsAppClick = () => {
		trackContactIntent("whatsapp");
	};

	const onCallClick = (e) => {
		e.preventDefault();
		trackContactIntent("phone");
		window.location.href = telHref;
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

	const appointmentFirstUnavailableMessage =
		appointmentFirstFlow && appointmentUnavailable
			? "Tu cita ya no está disponible para completar el pago. Puedes actualizar tu disponibilidad o solicitar que te llamemos para gestionar una nueva cita."
			: null;

	const confirmedStore =
		appointmentConfirmed && laboratoryAppointment.laboratory_store
			? laboratoryAppointment.laboratory_store
			: null;

	const showPendingCoordination =
		!appointmentConfirmed && !appointmentUnavailable;

	return (
		<Card className="bg-white p-5 sm:p-8 dark:bg-slate-900">
			<div className="space-y-5">
				{appointmentFirstUnavailableMessage && (
					<p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-100 dark:ring-amber-800">
						{appointmentFirstUnavailableMessage}
					</p>
				)}

				<AppointmentStepHeader />

				<AppointmentStudiesNotice
					studyItems={studyItemsRequiringAppointment}
				/>

				<AppointmentStatusSummary
					hasSavedAvailability={hasSavedAvailability}
					appointmentConfirmed={appointmentConfirmed}
					appointmentUnavailable={appointmentUnavailable}
					appointmentFirstFlow={appointmentFirstFlow}
				/>

				{confirmedStore && (
					<AppointmentConfirmedStorePanel store={confirmedStore} />
				)}

				<AppointmentPreferredStorePanel
					selection={selectedLaboratoryStore}
				/>

				<AppointmentSchedulePanel
					appointmentConfirmed={appointmentConfirmed}
					formattedAppointmentDate={
						laboratoryAppointment.formatted_appointment_date
					}
					formattedCallbackAvailabilityRange={
						laboratoryAppointment.formatted_callback_availability_range
					}
					hasSavedAvailability={hasSavedAvailability}
				/>

				{showPendingCoordination && appointmentFirstFlow && (
					<AppointmentPaymentSafetyNotice />
				)}

				{showPendingCoordination ? (
					<AppointmentPendingCard>
						<AppointmentContactActions
							telHref={telHref}
							phoneDisplay={phoneDisplay}
							whatsAppUrl={whatsAppUrl}
							whatsAppDisplay={whatsAppDisplay}
							onWhatsAppClick={onWhatsAppClick}
							onCallClick={onCallClick}
							onRequestCall={openReceiveCallForm}
							isFormOpen={openPanel === "form"}
						/>
					</AppointmentPendingCard>
				) : (
					<AppointmentContactActions
						telHref={telHref}
						phoneDisplay={phoneDisplay}
						whatsAppUrl={whatsAppUrl}
						whatsAppDisplay={whatsAppDisplay}
						onWhatsAppClick={onWhatsAppClick}
						onCallClick={onCallClick}
						onRequestCall={openReceiveCallForm}
						isFormOpen={openPanel === "form"}
					/>
				)}

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

				{showPendingCoordination && (
					<AppointmentPaymentAuthorizationNote />
				)}
			</div>

			{appointmentConfirmed || appointmentUnavailable ? (
				<p className="mt-5 flex items-center justify-center gap-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
					<LockClosedIcon
						className="size-4 shrink-0"
						aria-hidden="true"
					/>
					<span>
						{appointmentFirstFlow
							? "Tus datos están guardados. No realizaremos ningún cargo todavía."
							: "Tus datos están guardados de forma segura."}
					</span>
				</p>
			) : null}
		</Card>
	);
}
