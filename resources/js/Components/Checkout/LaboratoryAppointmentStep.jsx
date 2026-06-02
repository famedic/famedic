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
import { Badge } from "@/Components/Catalyst/badge";
import { DevicePhoneMobileIcon } from "@heroicons/react/16/solid";
import { motion } from "framer-motion";
import { useState, useEffect, useMemo, useRef } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import clsx from "clsx";
import CheckoutWizardStep from "@/Components/Checkout/CheckoutWizardStep";
import CheckoutSaveSuccessAlert from "@/Components/Checkout/CheckoutSaveSuccessAlert";

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

export default function LaboratoryAppointmentStep({
	laboratoryAppointment,
	callbackPreferenceSavedAtFormatted,
}) {
	const { auth } = usePage().props;
	const [showCheck, setShowCheck] = useState(true);
	const [tabIndex, setTabIndex] = useState(0);
	const [minNowTick, setMinNowTick] = useState(() => minStartDatetimeLocal());
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

		setTabIndex(
			hasSavedCallbackProgress ? TAB_TRACKING : TAB_CALL_NOW,
		);
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
	const hasTrackingContent =
		Boolean(requestSavedAtFormatted) || hasSavedAvailability;

	return (
		<CheckoutWizardStep
			title="Agendar tu cita"
			description="Llama ahora, indica cuándo contactarte o consulta el seguimiento de tu solicitud."
		>
			<TabGroup selectedIndex={tabIndex} onChange={setTabIndex}>
				<TabList className="grid grid-cols-3 gap-2 rounded-xl bg-zinc-100 p-1.5 dark:bg-zinc-800/80">
					<Tab className="rounded-lg py-3 text-sm font-semibold outline-none transition-colors sm:py-2.5">
						{(selected) => (
							<span
								className={clsx(
									"flex flex-col items-center gap-0.5 rounded-md px-2 py-1 sm:flex-row sm:justify-center sm:gap-2",
									selected
										? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-900 dark:text-white"
										: "text-zinc-500 dark:text-zinc-400",
								)}
							>
								<PhoneIcon className="size-5 shrink-0" />
								Llamar ahora
							</span>
						)}
					</Tab>
					<Tab className="rounded-lg py-3 text-sm font-semibold outline-none transition-colors sm:py-2.5">
						{(selected) => (
							<span
								className={clsx(
									"flex flex-col items-center gap-0.5 rounded-md px-2 py-1 sm:flex-row sm:justify-center sm:gap-2",
									selected
										? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-900 dark:text-white"
										: "text-zinc-500 dark:text-zinc-400",
								)}
							>
								<CalendarDaysIcon className="size-5 shrink-0" />
								Recibir llamada
							</span>
						)}
					</Tab>
					<Tab className="rounded-lg py-3 text-sm font-semibold outline-none transition-colors sm:py-2.5">
						{(selected) => (
							<span
								className={clsx(
									"flex flex-col items-center gap-0.5 rounded-md px-2 py-1 sm:flex-row sm:justify-center sm:gap-2",
									selected
										? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-900 dark:text-white"
										: "text-zinc-500 dark:text-zinc-400",
								)}
							>
								<ClipboardDocumentListIcon className="size-5 shrink-0" />
								Seguimiento
							</span>
						)}
					</Tab>
				</TabList>

				<TabPanels className="mt-6">
					<TabPanel className="outline-none">
						<div className="flex justify-center">
							<div className="relative mx-auto inline-flex">
								<motion.div
									initial={{ scale: 0.5 }}
									animate={{ scale: 1 }}
									exit={{ scale: 0 }}
									transition={{ duration: 3 }}
									onAnimationComplete={() => setShowCheck(false)}
								>
									{showCheck ? (
										<CheckIcon className="size-20 text-green-600 dark:text-green-200" />
									) : (
										<>
											<div className="absolute left-1/2 size-6 animate-bounce rounded-full bg-green-400 dark:bg-green-300" />
											<PhoneIcon className="relative size-20 animate-[shake_2s_infinite] fill-green-600 dark:fill-green-200" />
										</>
									)}
								</motion.div>
							</div>
						</div>
						<div className="text-center">
							<Subheading className="text-center">
								Llama ahora para agendar tu cita
							</Subheading>
							<a href={telHref} onClick={onCallClick} className="mt-4 inline-block">
								<Button type="button">
									<PhoneIcon />
									(55) 6651 5232
								</Button>
							</a>
							<Text className="mt-4 flex flex-wrap items-center justify-center text-sm text-zinc-700 dark:text-zinc-200">
								<span>
									Si no puedes llamar ahora, te contactaremos al
								</span>
								<Badge color="sky" className="mx-1">
									<DevicePhoneMobileIcon className="size-4" />
									{auth?.user?.phone}.
								</Badge>
							</Text>
							<button
								type="button"
								className="mt-6 text-sm font-medium text-sky-600 underline decoration-sky-600/30 underline-offset-2 hover:text-sky-700 dark:text-sky-400"
								onClick={() => setTabIndex(TAB_RECEIVE_CALL)}
							>
								← Prefiero indicar cuándo llamarme
							</button>
						</div>
					</TabPanel>

					<TabPanel className="rounded-2xl border border-zinc-200/80 bg-white p-5 text-zinc-900 shadow-sm outline-none dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-100 sm:p-6">
						<Subheading className="text-center text-base">
							¿Cuándo podemos llamarte?
						</Subheading>
						<Text className="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
							Elige recibir llamada ahora o programa una ventana para más tarde.
						</Text>

						<div className="mt-6 grid gap-3">
							<button
								type="button"
								onClick={() => setReceiveCallMode("now")}
								className={clsx(
									"flex items-center justify-between rounded-xl border px-4 py-3 text-left",
									receiveCallMode === "now"
										? "border-sky-500 bg-sky-50 dark:border-sky-500 dark:bg-sky-900/20"
										: "border-zinc-200 dark:border-zinc-700",
								)}
							>
								<span className="font-medium">Recibir llamada ahora</span>
								<span className="text-sky-700 dark:text-sky-300">
									{receiveCallMode === "now" ? "✓" : ""}
								</span>
							</button>
							<button
								type="button"
								onClick={() => setReceiveCallMode("later")}
								className={clsx(
									"flex items-center justify-between rounded-xl border px-4 py-3 text-left",
									receiveCallMode === "later"
										? "border-sky-500 bg-sky-50 dark:border-sky-500 dark:bg-sky-900/20"
										: "border-zinc-200 dark:border-zinc-700",
								)}
							>
								<span className="font-medium">Más tarde</span>
								<span className="text-sky-700 dark:text-sky-300">
									{receiveCallMode === "later" ? "✓" : ""}
								</span>
							</button>
						</div>

						<div className="mt-6 space-y-6">
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
									rows={4}
									value={data.patient_callback_comment}
									onChange={(e) =>
										setData("patient_callback_comment", e.target.value)
									}
									placeholder="Ej. solo contesto después de las 6 p.m. entre semana…"
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
									<ErrorMessage>
										{errors.callback_availability_ends_at}
									</ErrorMessage>
								)}
							</Field>
							<div className="flex flex-col items-center gap-2">
								<Button
									type="button"
									disabled={submittingAvailability || !canSave}
									onClick={submitAvailability}
								>
									{submittingAvailability
										? "Actualizando…"
										: "Actualizar disponibilidad"}
								</Button>
								{!canSave && (
									<Text className="text-center text-xs text-zinc-500 dark:text-zinc-400">
										Escribe un comentario o confirma un horario válido
										para guardar.
									</Text>
								)}
							</div>
						</div>
					</TabPanel>

					<TabPanel className="rounded-2xl border border-zinc-200/80 bg-white p-5 outline-none dark:border-zinc-700 dark:bg-zinc-900/40 sm:p-6">
						<Subheading className="text-center text-base">
							Seguimiento de tu solicitud
						</Subheading>
						<Text className="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
							Resumen de lo que ya registraste para esta cita.
						</Text>

						<div className="mt-6 space-y-4">
							{requestSavedAtFormatted ? (
								<CheckoutSaveSuccessAlert
									message="Tu solicitud de cita se guardó correctamente"
									hint={
										<>
											<Strong>Solicitud:</Strong> el{" "}
											{requestSavedAtFormatted} · <br />
											{laboratoryAppointment.patient_full_name && (
												<>
													<Strong>Paciente:</Strong>{" "}
													{laboratoryAppointment.patient_full_name}{" "}
													· <br />
												</>
											)}
											Un asesor te contactará para agendar tu cita y
											confirmar fecha y sucursal.
										</>
									}
								/>
							) : (
								<Text className="text-center text-sm text-zinc-500 dark:text-zinc-400">
									Aún no hay solicitud de cita registrada.
								</Text>
							)}

							{hasSavedAvailability ? (
								<CheckoutSaveSuccessAlert
									message="Tu disponibilidad para recibir llamada quedó registrada"
									hint={
										<>
											{callbackPreferenceSavedAtFormatted && (
												<>
													<Strong>Actualización:</Strong> el{" "}
													{callbackPreferenceSavedAtFormatted}
													<br />
												</>
											)}
											{laboratoryAppointment.formatted_callback_availability_range && (
												<>
													<Strong>Horario:</Strong>{" "}
													{
														laboratoryAppointment.formatted_callback_availability_range
													}
													<br />
												</>
											)}
											{laboratoryAppointment.patient_callback_comment?.trim() && (
												<>
													<Strong>Comentarios:</Strong>{" "}
													{laboratoryAppointment.patient_callback_comment.trim()}
												</>
											)}
										</>
									}
								/>
							) : (
								<Text className="text-center text-sm text-zinc-500 dark:text-zinc-400">
									No has registrado disponibilidad para recibir llamada.
								</Text>
							)}

							{hasTrackingContent && (
								<p className="text-center text-xs text-zinc-500 dark:text-zinc-400">
									Puedes actualizar tu disponibilidad en la pestaña{" "}
									<button
										type="button"
										className="font-medium text-sky-600 underline underline-offset-2 dark:text-sky-400"
										onClick={() => setTabIndex(TAB_RECEIVE_CALL)}
									>
										Recibir llamada
									</button>
									.
								</p>
							)}
						</div>
					</TabPanel>
				</TabPanels>
			</TabGroup>

			<Text className="mt-6 text-center text-sm text-zinc-600 dark:text-slate-400">
				Cuando un asesor confirme tu cita, avanzarás automáticamente al resumen para pagar.
			</Text>
		</CheckoutWizardStep>
	);
}
