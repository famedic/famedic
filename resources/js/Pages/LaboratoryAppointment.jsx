import FocusedLayout from "@/Layouts/FocusedLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { CheckIcon, PhoneIcon } from "@heroicons/react/20/solid";
import {
	DevicePhoneMobileIcon,
	ArrowLeftIcon,
} from "@heroicons/react/16/solid";
import {
	CalendarDaysIcon,
} from "@heroicons/react/24/outline";
import { Badge } from "@/Components/Catalyst/badge";
import { motion } from "framer-motion";
import { useState, useEffect, useMemo, useRef } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
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
import clsx from "clsx";

function toDatetimeLocal(value) {
	if (!value) return "";
	const d = new Date(value);
	if (Number.isNaN(d.getTime())) return "";
	const pad = (n) => String(n).padStart(2, "0");
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Estrictamente posterior a `now` (siguiente minuto). */
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

export default function LaboratoryAppointment({
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

	/**
	 * Evita re-hidratar día/horas desde el servidor en cada `router.reload()` o
	 * re-render con nueva referencia de props si los valores guardados no cambiaron.
	 */
	const hydratedFromServerKeyRef = useRef("");

	const { data, setData, patch, processing, errors, recentlySuccessful } =
		useForm({
			callback_availability_starts_at: toDatetimeLocal(
				laboratoryAppointment.callback_availability_starts_at,
			),
			callback_availability_ends_at: toDatetimeLocal(
				laboratoryAppointment.callback_availability_ends_at,
			),
			patient_callback_comment:
				laboratoryAppointment.patient_callback_comment ?? "",
		});

	/* Actualizar piso de "ahora" cada 30s para que min siga siendo futuro */
	useEffect(() => {
		const t = setInterval(() => setMinNowTick(minStartDatetimeLocal()), 30000);
		return () => clearInterval(t);
	}, []);

	/* Valores guardados en el pasado: limpiar para respetar solo fechas futuras */
	useEffect(() => {
		const ms = new Date(minStartDatetimeLocal());
		const s = data.callback_availability_starts_at;
		const e = data.callback_availability_ends_at;
		if (s && new Date(s) < ms) {
			setData({
				callback_availability_starts_at: "",
				callback_availability_ends_at: "",
				patient_callback_comment: data.patient_callback_comment,
			});
			return;
		}
		if (s && e && new Date(e) <= new Date(s)) {
			setData({
				callback_availability_starts_at: s,
				callback_availability_ends_at: "",
				patient_callback_comment: data.patient_callback_comment,
			});
		}
		// Solo al montar / carga inicial
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	useEffect(() => {
		const intervalId = setInterval(() => {
			router.reload();
		}, 10000);
		return () => clearInterval(intervalId);
	}, []);

	useEffect(() => {
		const serverKey = [
			laboratoryAppointment.id,
			laboratoryAppointment.callback_availability_starts_at ?? "",
			laboratoryAppointment.callback_availability_ends_at ?? "",
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
		}
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

	const submitAvailability = (e) => {
		e.preventDefault();
		if (!processing && canSave) {
			patch(
				route("laboratory-appointments.callback-availability", {
					laboratory_brand: laboratoryAppointment.brand,
					laboratory_appointment: laboratoryAppointment.id,
				}),
			);
		}
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

	const hasSavedCallbackPreference = Boolean(callbackPreferenceSavedAtFormatted);

	return (
		<FocusedLayout title="Cita de laboratorio">
			<div className="mx-auto max-w-2xl px-4 pb-28 pt-8 sm:px-0 sm:pb-12 sm:pt-12">
				<Text className="text-center text-sm text-zinc-500 dark:text-zinc-400">
					Paso pendiente: confirma tu cita con el laboratorio
				</Text>

				<TabGroup selectedIndex={tabIndex} onChange={setTabIndex}>
					<TabList className="mt-6 grid grid-cols-2 gap-2 rounded-xl bg-zinc-100 p-1.5 dark:bg-zinc-800/80">
						<Tab
							className={clsx(
								"rounded-lg py-3 text-sm font-semibold outline-none transition-colors sm:py-2.5",
							)}
						>
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
						<Tab
							className={clsx(
								"rounded-lg py-3 text-sm font-semibold outline-none transition-colors sm:py-2.5",
							)}
						>
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
					</TabList>

					<TabPanels className="mt-8">
						<TabPanel className="outline-none">
							<div className="flex justify-center">
								<div className="relative mx-auto inline-flex">
									<motion.div
										initial={{ scale: 0.5 }}
										animate={{ scale: 1 }}
										exit={{ scale: 0 }}
										transition={{ duration: 3 }}
										onAnimationComplete={() => {
											setShowCheck(false);
										}}
									>
										{showCheck ? (
											<CheckIcon className="size-28 text-green-600 dark:text-green-200 sm:size-32" />
										) : (
											<>
												<div className="absolute left-1/2 size-6 animate-bounce rounded-full bg-green-400 dark:bg-green-300"></div>
												<PhoneIcon className="relative size-28 animate-[shake_2s_infinite] fill-green-600 dark:fill-green-200 sm:size-32" />
											</>
										)}
									</motion.div>
								</div>
							</div>
							<div className="text-center">
								<GradientHeading>
									<span className="text-center">
										Llama ahora para agendar tu cita
									</span>
								</GradientHeading>
								<a href={telHref} onClick={onCallClick}>
									<Button type="button">
										<PhoneIcon />
										(55) 6651 5232
									</Button>
								</a>
								<Text className="mt-6 flex flex-wrap items-center justify-center text-sm text-zinc-700 dark:text-zinc-200">
									<span>
										Si no te es posible llamar en este momento,
										nuestro equipo se pondrá en contacto contigo
										al
									</span>
									<Badge color="sky" className="mx-1">
										<DevicePhoneMobileIcon className="size-4" />
										{auth?.user?.phone}.
									</Badge>
									<span>Si tu número ha cambiado, puedes</span>
									<TextLink
										href={route("user.edit")}
										className="mx-1"
									>
										actualizarlo aquí.
									</TextLink>
								</Text>

								<Text className="mt-4 text-sm text-zinc-700 dark:text-zinc-200">
									Una vez que hayas confirmado tu cita, podrás
									continuar con tu compra.
								</Text>

								<button
									type="button"
									className="mt-8 text-sm font-medium text-sky-600 underline decoration-sky-600/30 underline-offset-2 hover:text-sky-700 dark:text-sky-400"
									onClick={() => setTabIndex(1)}
								>
									← Prefiero indicar cuándo llamarme
								</button>
							</div>
						</TabPanel>

						<TabPanel className="rounded-2xl border border-zinc-200/80 bg-white p-5 text-zinc-900 shadow-sm outline-none dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-100 sm:p-7">
							<Subheading className="text-center text-base">
								¿Cuándo podemos llamarte?
							</Subheading>
							<Text className="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
								Elige recibir llamada ahora o programa una ventana
								para más tarde.
							</Text>

							<div className="mt-6 grid gap-3">
								<button
									type="button"
									onClick={() => setReceiveCallMode("now")}
									className={clsx(
										"flex items-center justify-between rounded-xl border px-4 py-3 text-left text-zinc-900 dark:text-zinc-100",
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
										"flex items-center justify-between rounded-xl border px-4 py-3 text-left text-zinc-900 dark:text-zinc-100",
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

							{hasSavedCallbackPreference && (
								<div
									className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50/90 px-4 py-3 text-left shadow-sm dark:border-emerald-900/60 dark:bg-emerald-950/40"
									role="status"
								>
									<div className="flex gap-3">
										<div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
											<CheckIcon className="size-6 text-emerald-700 dark:text-emerald-300" />
										</div>
										<div className="min-w-0 flex-1 space-y-1">
											<Text className="font-semibold text-emerald-900 dark:text-emerald-100">
												Tu información ya está en el laboratorio
											</Text>
											<Text className="text-sm text-emerald-800/95 dark:text-emerald-200/90">
												Registramos tu disponibilidad y comentarios.
												<br />
												Puedes actualizarlos cuando quieras.
											</Text>
											<Text className="text-xs font-medium text-emerald-700/90 dark:text-emerald-300/90">
												Enviado el{" "}
												<span className="whitespace-normal">
													{callbackPreferenceSavedAtFormatted}
												</span>
											</Text>
										</div>
									</div>
								</div>
							)}

							<form onSubmit={submitAvailability} className="mt-8 space-y-6">
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
													onChange={(e) =>
														setEndTime(e.target.value)
													}
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
											setData(
												"patient_callback_comment",
												e.target.value,
											)
										}
										placeholder="Ej. solo contesto después de las 6 p.m. entre semana…"
									/>
									{errors.patient_callback_comment && (
										<ErrorMessage>
											{errors.patient_callback_comment}
										</ErrorMessage>
									)}
								</Field>
								<div className="flex flex-col items-center gap-2">
									<Button
										type="submit"
										disabled={processing || !canSave}
									>
										{processing
											? "Guardando…"
											: hasSavedCallbackPreference
												? "Actualizar disponibilidad"
												: "Guardar disponibilidad"}
									</Button>
									{!canSave && (
										<Text className="max-w-sm text-center text-xs text-zinc-600 dark:text-zinc-300">
											Completa ambos horarios o escribe un
											comentario para habilitar el guardado.
										</Text>
									)}
									{recentlySuccessful && (
										<Text className="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
											{hasSavedCallbackPreference
												? "Cambios guardados correctamente."
												: "Listo: guardamos tu información para el equipo del laboratorio."}
										</Text>
									)}
								</div>
							</form>
						</TabPanel>
					</TabPanels>
				</TabGroup>

				<div className="mt-10 flex justify-center">
					<Button
						plain
						href={route("laboratory.shopping-cart", {
							laboratory_brand: laboratoryAppointment.brand,
						})}
					>
						<ArrowLeftIcon />
						Regresar al carrito
					</Button>
				</div>
			</div>
		</FocusedLayout>
	);
}
