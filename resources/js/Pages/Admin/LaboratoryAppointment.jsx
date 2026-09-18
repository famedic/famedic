import { useMemo, useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import clsx from "clsx";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import {
	BeakerIcon,
	CalendarDaysIcon,
	ChatBubbleLeftRightIcon,
	TrashIcon,
	BuildingOffice2Icon,
	ClockIcon,
	UserIcon,
	PhoneIcon,
	PencilSquareIcon,
	ClipboardDocumentListIcon,
	ClockIcon as ClockOutlineIcon,
	DocumentTextIcon,
	CheckCircleIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { Select } from "@/Components/Catalyst/select";
import {
	Field,
	Label,
	ErrorMessage,
	Description,
} from "@/Components/Catalyst/fieldset";
import {
	Dialog,
	DialogTitle,
	DialogBody,
	DialogActions,
	DialogDescription,
} from "@/Components/Catalyst/dialog";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import CountryListbox from "@/Components/CountryListbox";
import AppointmentHeader from "@/Pages/Admin/LaboratoryAppointment/AppointmentHeader";
import PurchaseStatusCard from "@/Pages/Admin/LaboratoryAppointment/PurchaseStatusCard";
import PatientInfoCard from "@/Pages/Admin/LaboratoryAppointment/PatientInfoCard";
import PatientCallbackInfoCard from "@/Pages/Admin/LaboratoryAppointment/PatientCallbackInfoCard";
import ContactInfoCard from "@/Pages/Admin/LaboratoryAppointment/ContactInfoCard";
import StudiesTable from "@/Pages/Admin/LaboratoryAppointment/StudiesTable";
import AppointmentSidebar from "@/Pages/Admin/LaboratoryAppointment/AppointmentSidebar";
import LaboratoryAppointmentEmailActions from "@/Pages/Admin/LaboratoryAppointment/LaboratoryAppointmentEmailActions";
import { appointmentStoreRecommendationPresentation } from "@/lib/laboratoryAppointmentRecommendation";

const TABS = [
	{ id: "overview", label: "Vista general" },
	{ id: "studies", label: "Estudios" },
	{ id: "request", label: "Solicitud y disponibilidad" },
	{ id: "payments", label: "Pagos" },
	{ id: "history", label: "Historial" },
	{ id: "notes", label: "Notas" },
];

const APPOINTMENT_DATE_ERRORS = {
	PAST_DATE: "No puedes seleccionar una fecha anterior a hoy.",
	PAST_TIME:
		"Para una cita hoy, selecciona una hora posterior a la hora actual.",
	PAST_DATE_TIME:
		"La fecha y hora seleccionadas ya pasaron. Selecciona una fecha y hora futuras.",
};

function padDatePart(value) {
	return String(value).padStart(2, "0");
}

function formatDateInputValue(date = new Date()) {
	return [
		date.getFullYear(),
		padDatePart(date.getMonth() + 1),
		padDatePart(date.getDate()),
	].join("-");
}

function formatTimeInputValue(date = new Date()) {
	return [padDatePart(date.getHours()), padDatePart(date.getMinutes())].join(
		":",
	);
}

function getMinimumTimeForToday(now = new Date()) {
	const nextMinute = new Date(now);
	nextMinute.setSeconds(0, 0);
	nextMinute.setMinutes(nextMinute.getMinutes() + 1);

	if (formatDateInputValue(nextMinute) !== formatDateInputValue(now)) {
		return "23:59";
	}

	return formatTimeInputValue(nextMinute);
}

function parseLocalDateTime(dateValue, timeValue) {
	if (!dateValue || !timeValue) {
		return null;
	}

	const [year, month, day] = dateValue.split("-").map(Number);
	const [hour, minute] = timeValue.split(":").map(Number);

	if ([year, month, day, hour, minute].some((value) => Number.isNaN(value))) {
		return null;
	}

	return new Date(year, month - 1, day, hour, minute, 0, 0);
}

function validateAppointmentDateTime(
	dateValue,
	timeValue,
	now = new Date(),
	{ pastTimeMessage = APPOINTMENT_DATE_ERRORS.PAST_TIME } = {},
) {
	const today = formatDateInputValue(now);

	if (dateValue && dateValue < today) {
		return {
			field: "appointment_date",
			message: APPOINTMENT_DATE_ERRORS.PAST_DATE,
		};
	}

	const appointmentDateTime = parseLocalDateTime(dateValue, timeValue);

	if (appointmentDateTime && appointmentDateTime <= now) {
		return {
			field: "appointment_time",
			message:
				dateValue === today
					? pastTimeMessage
					: APPOINTMENT_DATE_ERRORS.PAST_DATE_TIME,
		};
	}

	return null;
}

function formatReadableDate(dateValue, options = {}) {
	const parsedDate = parseLocalDateTime(dateValue, "00:00");

	if (!parsedDate) {
		return "Sin fecha";
	}

	return new Intl.DateTimeFormat("es-MX", {
		day: "numeric",
		month: "short",
		year: "numeric",
		...options,
	}).format(parsedDate);
}

function formatReadableTime(timeValue) {
	if (!timeValue) {
		return "Sin hora";
	}

	const parsedDate = parseLocalDateTime("2000-01-01", timeValue);

	if (!parsedDate) {
		return timeValue;
	}

	return new Intl.DateTimeFormat("es-MX", {
		hour: "numeric",
		minute: "2-digit",
	}).format(parsedDate);
}

export default function LaboratoryAppointment({
	laboratoryAppointment,
	laboratoryStores,
	studyItems,
	studyItemsSource,
	interactions,
	hasPaidLaboratoryPurchase,
	callbackPreferenceSavedAtFormatted = null,
	checkoutProgress = null,
	selectedStoreRecommendation = null,
}) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);
	const [openConfirmation, setOpenConfirmation] = useState(false);
	const [activeTab, setActiveTab] = useState("overview");

	const appointmentStatus = laboratoryAppointment.deleted_at
		? "cancelled"
		: laboratoryAppointment.confirmed_at
			? "completed"
			: "pending";

	const purchase = laboratoryAppointment.laboratory_purchase ?? null;
	const purchaseStatus = hasPaidLaboratoryPurchase ? "paid" : "pending";

	const studies = useMemo(() => {
		if (
			laboratoryAppointment.laboratory_purchase?.laboratory_purchase_items
				?.length
		) {
			const purchase = laboratoryAppointment.laboratory_purchase;
			const baseStatus = purchase.has_results_available
				? "completed"
				: "pending";

			return purchase.laboratory_purchase_items.map((item) => ({
				id: item.id,
				name: item.name,
				gdaId: item.gda_id ?? null,
				description: item.description ?? item.instructions ?? null,
				cost: item.formatted_price ?? null,
				priceCents: item.price_cents ?? null,
				requiresAppointment:
					item.requires_appointment === true ||
					item.requiresAppointment === true,
				status: baseStatus,
				sampleType: "Muestra sanguinea",
				performedAt: purchase.formatted_results_at ?? null,
			}));
		}

		return (studyItems ?? []).map((item) => ({
			id: item.id,
			name: item.name,
			gdaId: item.gda_id ?? null,
			description: item.description ?? item.instructions ?? null,
			cost: item.formatted_price ?? null,
			priceCents: item.price_cents ?? null,
			requiresAppointment:
				item.requires_appointment === true ||
				item.requiresAppointment === true,
			status: "pending",
			sampleType:
				item.requires_appointment === true
					? "En laboratorio"
					: "A domicilio",
			performedAt: null,
		}));
	}, [laboratoryAppointment, studyItems]);

	const patient = {
		fullName: laboratoryAppointment.patient_full_name ?? "...",
		gender: laboratoryAppointment.formatted_patient_gender ?? "...",
		phone: laboratoryAppointment.patient_full_phone
			? `${laboratoryAppointment.patient_phone_country} ${laboratoryAppointment.patient_phone}`
			: "...",
		birthDate: laboratoryAppointment.formatted_patient_birth_date ?? "...",
	};

	const contact = {
		name: laboratoryAppointment.customer.user.full_name,
		email: laboratoryAppointment.customer.user.email,
		phone:
			laboratoryAppointment.customer.user.full_phone ??
			laboratoryAppointment.customer.user.phone ??
			"...",
	};

	const summary = {
		statusLabel:
			appointmentStatus === "completed"
				? "Completada"
				: appointmentStatus === "cancelled"
					? "Cancelada"
					: "Pendiente",
		statusColor:
			appointmentStatus === "completed"
				? "emerald"
				: appointmentStatus === "cancelled"
					? "red"
					: "amber",
		laboratory: laboratoryAppointment.brand?.toUpperCase() ?? "Laboratorio",
		totalStudies: studies.length,
		paymentLabel: hasPaidLaboratoryPurchase ? "Pagado" : "Sin pago",
		paymentColor: hasPaidLaboratoryPurchase ? "emerald" : "amber",
		channel: "Plataforma",
		totalTime: laboratoryAppointment.formatted_created_at ?? "...",
	};

	const scheduleLabel = laboratoryAppointment.confirmed_at
		? "Editar cita"
		: "Agendar cita";

	const headerActions = (
		<>
			<LaboratoryAppointmentEmailActions
				appointment={laboratoryAppointment}
				hasPaidLaboratoryPurchase={hasPaidLaboratoryPurchase}
				compact
			/>
			{!laboratoryAppointment.confirmed_at && (
				<Button
					outline
					dusk="deleteLaboratoryAppointment"
					onClick={() => setOpenDeleteConfirmation(true)}
				>
					<TrashIcon className="stroke-red-400" />
					Eliminar
				</Button>
			)}
			<Button onClick={() => setOpenConfirmation(true)}>
				<CalendarDaysIcon />
				{scheduleLabel}
			</Button>
		</>
	);

	return (
		<AdminLayout title="Cita de laboratorio">
			<div className="space-y-4">
				<AppointmentHeader
					appointment={laboratoryAppointment}
					status={appointmentStatus}
					showEditButton={false}
					actions={headerActions}
				/>

				<AppointmentTabs
					tabs={TABS}
					activeTab={activeTab}
					onChange={setActiveTab}
				/>

				<div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
					<main className="min-w-0 space-y-3">
						{activeTab === "overview" && (
							<OverviewTab
								patient={patient}
								contact={contact}
								appointment={laboratoryAppointment}
								purchaseStatus={purchaseStatus}
								orderNumber={
									purchase?.gda_order_id ??
									purchase?.transactions?.[0]?.reference_id
								}
								purchaseDate={
									purchase?.formatted_created_at ??
									purchase?.transactions?.[0]
										?.formatted_created_at ??
									null
								}
								studies={studies}
								checkoutProgress={checkoutProgress}
							/>
						)}
						{activeTab === "studies" && (
							<StudiesTable studies={studies} />
						)}
						{activeTab === "request" && (
							<PatientCallbackInfoCard
								appointment={laboratoryAppointment}
								callbackPreferenceSavedAtFormatted={
									callbackPreferenceSavedAtFormatted
								}
								onSchedule={() => setOpenConfirmation(true)}
								scheduleLabel={scheduleLabel}
							/>
						)}
						{activeTab === "payments" && (
							<PurchaseStatusCard
								purchaseStatus={purchaseStatus}
								orderNumber={
									purchase?.gda_order_id ??
									purchase?.transactions?.[0]?.reference_id
								}
								purchaseDate={
									purchase?.formatted_created_at ??
									purchase?.transactions?.[0]
										?.formatted_created_at ??
									null
								}
								studies={studies}
								checkoutProgress={checkoutProgress}
							/>
						)}
						{activeTab === "history" && (
							<InteractionHistory interactions={interactions} />
						)}
						{activeTab === "notes" && (
							<NotesPanel appointment={laboratoryAppointment} />
						)}
					</main>

					<aside className="min-w-0 xl:sticky xl:top-4 xl:self-start">
						<AppointmentSidebar
							appointment={laboratoryAppointment}
							summary={summary}
							actions={
								<QuickActions
									appointment={laboratoryAppointment}
									hasPaidLaboratoryPurchase={
										hasPaidLaboratoryPurchase
									}
									onSchedule={() => setOpenConfirmation(true)}
									scheduleLabel={scheduleLabel}
								/>
							}
						/>
					</aside>
				</div>
			</div>

			<LaboratoryAppointmentDeleteDialog
				laboratoryAppointment={laboratoryAppointment}
				openDeleteConfirmation={openDeleteConfirmation}
				setOpenDeleteConfirmation={setOpenDeleteConfirmation}
			/>

			<LaboratoryAppointmentConfirmationDialog
				laboratoryAppointment={laboratoryAppointment}
				laboratoryStores={laboratoryStores}
				studyItems={studyItems}
				studies={studies}
				hasPaidLaboratoryPurchase={hasPaidLaboratoryPurchase}
				selectedStoreRecommendation={selectedStoreRecommendation}
				setOpenConfirmation={setOpenConfirmation}
				openConfirmation={openConfirmation}
			/>
		</AdminLayout>
	);
}

function AppointmentTabs({ tabs, activeTab, onChange }) {
	return (
		<div className="overflow-x-auto border-b border-zinc-200/70 dark:border-zinc-800">
			<div
				className="flex min-w-max gap-1"
				role="tablist"
				aria-label="Detalle de cita"
			>
				{tabs.map((tab) => (
					<button
						key={tab.id}
						type="button"
						role="tab"
						aria-selected={activeTab === tab.id}
						onClick={() => onChange(tab.id)}
						className={clsx(
							"rounded-t-lg px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-500",
							activeTab === tab.id
								? "border-b-2 border-sky-600 text-sky-700 dark:border-sky-400 dark:text-sky-300"
								: "text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100",
						)}
					>
						{tab.label}
					</button>
				))}
			</div>
		</div>
	);
}

function OverviewTab({
	patient,
	contact,
	appointment,
	purchaseStatus,
	orderNumber,
	purchaseDate,
	studies,
	checkoutProgress,
}) {
	return (
		<>
			<PurchaseStatusCard
				purchaseStatus={purchaseStatus}
				orderNumber={orderNumber}
				purchaseDate={purchaseDate}
				studies={studies}
				checkoutProgress={checkoutProgress}
				compact
			/>

				<div className="grid gap-3 lg:grid-cols-2">
					<PatientInfoCard patient={patient} />
					<ContactInfoCard contact={contact} />
				</div>

				<StudiesTable studies={studies} compact />
			</>
		);
	}

function QuickActions({
	appointment,
	hasPaidLaboratoryPurchase,
	onSchedule,
	scheduleLabel,
}) {
	return (
		<div className="space-y-2">
			<Button className="w-full justify-center" onClick={onSchedule}>
				<CalendarDaysIcon />
				{scheduleLabel}
			</Button>
			<LaboratoryAppointmentEmailActions
				appointment={appointment}
				hasPaidLaboratoryPurchase={hasPaidLaboratoryPurchase}
				className="w-full justify-center"
				compact
			/>
		</div>
	);
}

function NotesPanel({ appointment, compact = false }) {
	return (
		<SectionCard
			title="Notas"
			icon={DocumentTextIcon}
			className={compact ? "h-full" : null}
		>
			<p
				className={clsx(
					"text-sm leading-6 text-zinc-700 dark:text-zinc-300",
					compact && "line-clamp-6",
				)}
			>
				{appointment.notes || "Sin notas para el cliente."}
			</p>
		</SectionCard>
	);
}

const INTERACTION_PRESENTATION = {
	patient_whatsapp_intent: {
		title: "Intento de contacto por WhatsApp",
		description:
			"El paciente hizo clic en el WhatsApp oficial para solicitar apoyo con su cita.",
		badge: "WhatsApp",
		color: "emerald",
		icon: ChatBubbleLeftRightIcon,
	},
	patient_phone_intent: {
		title: "Intento de llamada",
		description:
			"El paciente hizo clic en el telefono de atencion para llamar al equipo.",
		badge: "Llamada",
		color: "sky",
		icon: PhoneIcon,
	},
	patient_callback_preference: {
		title: "Solicitud para recibir llamada",
		description:
			"El paciente compartio su disponibilidad para que el equipo lo contacte.",
		badge: "Seguimiento",
		color: "amber",
		icon: PhoneIcon,
	},
	concierge_note: {
		title: "Nota del concierge",
		description: "El equipo agrego una nota interna sobre esta cita.",
		badge: "Nota",
		color: "zinc",
		icon: DocumentTextIcon,
	},
	concierge_outbound_call: {
		title: "Llamada del concierge",
		description: "El equipo registro un intento de llamada saliente.",
		badge: "Concierge",
		color: "violet",
		icon: PhoneIcon,
	},
	admin_bulk_soft_delete: {
		title: "Eliminacion administrativa",
		description: "La cita fue marcada para eliminacion por administracion.",
		badge: "Admin",
		color: "red",
		icon: TrashIcon,
	},
};

function interactionPresentation(interaction) {
	return (
		INTERACTION_PRESENTATION[interaction.type] ?? {
			title: interaction.type,
			description: interaction.body || "Interaccion registrada.",
			badge: "Evento",
			color: "zinc",
			icon: ClockOutlineIcon,
		}
	);
}

function formatInteractionMetadata(interaction) {
	const metadata = interaction.metadata ?? {};
	const items = [];

	if (metadata.context === "laboratory_checkout") {
		items.push("Checkout de laboratorio");
	}

	if (metadata.step) {
		items.push(`Paso: ${metadata.step}`);
	}

	if (metadata.channel && !["phone", "whatsapp"].includes(metadata.channel)) {
		items.push(`Canal: ${metadata.channel}`);
	}

	if (metadata.cart_id) {
		items.push(`Carrito #${metadata.cart_id}`);
	}

	if (metadata.current_url) {
		items.push("Origen guardado");
	}

	return items;
}

function InteractionHistory({ interactions = [] }) {
	return (
		<SectionCard title="Historial de interacciones" icon={ClockOutlineIcon}>
			{interactions.length === 0 ? (
				<p className="text-sm text-zinc-500 dark:text-zinc-400">
					Sin interacciones registradas.
				</p>
			) : (
				<ol className="space-y-3">
					{interactions.map((interaction) => {
						const presentation =
							interactionPresentation(interaction);
						const Icon = presentation.icon;
						const metadataItems =
							formatInteractionMetadata(interaction);

						return (
							<li
								key={interaction.id}
								className="rounded-lg border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-950"
							>
								<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
									<div className="flex min-w-0 gap-3">
										<span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
											<Icon
												className="size-5"
												aria-hidden="true"
											/>
										</span>
										<div className="min-w-0">
											<div className="flex flex-wrap items-center gap-2">
												<h4 className="text-sm font-semibold text-zinc-950 dark:text-white">
													{presentation.title}
												</h4>
												<Badge
													color={presentation.color}
												>
													{presentation.badge}
												</Badge>
											</div>
											<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
												{interaction.body ||
													presentation.description}
											</p>
										</div>
									</div>
									<span className="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">
										{formatInteractionDate(
											interaction.created_at,
										)}
									</span>
								</div>

								{metadataItems.length > 0 && (
									<div className="mt-3 flex flex-wrap gap-2 pl-12">
										{metadataItems.map((item) => (
											<span
												key={item}
												className="rounded-md bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300"
											>
												{item}
											</span>
										))}
									</div>
								)}

								{interaction.admin_user && (
									<p className="mt-2 pl-12 text-xs text-zinc-500 dark:text-zinc-400">
										Registrado por{" "}
										{interaction.admin_user.name ??
											interaction.admin_user.email}
									</p>
								)}
							</li>
						);
					})}
				</ol>
			)}
		</SectionCard>
	);
}

function SectionCard({
	title,
	icon: Icon = ClipboardDocumentListIcon,
	className,
	children,
}) {
	return (
		<section
			className={clsx(
				"rounded-xl border border-zinc-200/70 bg-white/85 p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70",
				className,
			)}
		>
			<h3 className="mb-3 inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				<Icon className="size-4" />
				{title}
			</h3>
			{children}
		</section>
	);
}

function formatInteractionDate(value) {
	if (!value) {
		return "---";
	}

	try {
		return new Intl.DateTimeFormat("es-MX", {
			day: "numeric",
			month: "short",
			year: "numeric",
			hour: "numeric",
			minute: "2-digit",
		}).format(new Date(value));
	} catch {
		return value;
	}
}

function LaboratoryAppointmentDeleteDialog({
	laboratoryAppointment,
	setOpenDeleteConfirmation,
	openDeleteConfirmation,
}) {
	const { delete: destroy, processing } = useForm({});

	const deleteLaboratoryAppointment = () => {
		if (!processing) {
			destroy(
				route("admin.laboratory-appointments.destroy", {
					laboratory_appointment: laboratoryAppointment,
				}),
			);
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={!!openDeleteConfirmation}
			close={() => setOpenDeleteConfirmation(false)}
			title="Eliminar cita"
			description="¿Estás seguro de que deseas eliminar esta cita?"
			processing={processing}
			destroy={deleteLaboratoryAppointment}
		/>
	);
}

function LaboratoryAppointmentConfirmationDialog({
	laboratoryAppointment,
	laboratoryStores,
	studyItems,
	studies,
	hasPaidLaboratoryPurchase,
	selectedStoreRecommendation,
	setOpenConfirmation,
	openConfirmation,
}) {
	const { genders } = usePage().props;
	const [appointmentDateTimeError, setAppointmentDateTimeError] =
		useState(null);
	const [currentStep, setCurrentStep] = useState(1);
	const [wizardErrors, setWizardErrors] = useState({});

	const { data, setData, put, processing, errors } = useForm({
		appointment_date: laboratoryAppointment.appointment_date_string ?? "",
		appointment_time: laboratoryAppointment.appointment_date_time ?? "",
		patient_name: laboratoryAppointment.patient_name ?? "",
		patient_paternal_lastname:
			laboratoryAppointment.patient_paternal_lastname ?? "",
		patient_maternal_lastname:
			laboratoryAppointment.patient_maternal_lastname ?? "",
		patient_phone: laboratoryAppointment.patient_phone ?? "",
		patient_phone_country:
			laboratoryAppointment.patient_phone_country ?? "MX",
		patient_birth_date:
			laboratoryAppointment.patient_birth_date_string ?? "",
		patient_gender: laboratoryAppointment.patient_gender ?? "",
		laboratory_store: laboratoryAppointment.laboratory_store?.id ?? "",
		notes: laboratoryAppointment.notes ?? "",
		send_notification_email: Boolean(hasPaidLaboratoryPurchase),
	});
	const minimumAppointmentDate = formatDateInputValue();
	const minimumAppointmentTime =
		data.appointment_date === minimumAppointmentDate
			? getMinimumTimeForToday()
			: undefined;

	const handleAppointmentDateChange = (value) => {
		const validationError = validateAppointmentDateTime(
			value,
			data.appointment_time,
		);

		setData("appointment_date", value);

		if (
			validationError?.field === "appointment_time" &&
			value === formatDateInputValue()
		) {
			setData("appointment_time", "");
			setAppointmentDateTimeError({
				field: "appointment_time",
				message: APPOINTMENT_DATE_ERRORS.PAST_TIME,
			});

			return;
		}

		setAppointmentDateTimeError(validationError);
	};

	const handleAppointmentTimeChange = (value) => {
		setData("appointment_time", value);
		setAppointmentDateTimeError(
			validateAppointmentDateTime(data.appointment_date, value),
		);
	};

	const submit = (e) => {
		e.preventDefault();

		if (currentStep !== 4) {
			goToNextStep();

			return;
		}

		const validationError = validateAppointmentDateTime(
			data.appointment_date,
			data.appointment_time,
			new Date(),
			{
				pastTimeMessage: APPOINTMENT_DATE_ERRORS.PAST_DATE_TIME,
			},
		);

		if (validationError) {
			setAppointmentDateTimeError({
				...validationError,
				message:
					"No puedes agendar una cita en una fecha y hora que ya pasó.",
			});

			return;
		}

		if (!processing) {
			put(
				route("admin.laboratory-appointments.update", {
					laboratory_appointment: laboratoryAppointment,
				}),
				{
					onSuccess: () => {
						setOpenConfirmation(false);
					},
				},
			);
		}
	};

	const appointmentDateError =
		appointmentDateTimeError?.field === "appointment_date"
			? appointmentDateTimeError.message
			: errors.appointment_date;
	const appointmentTimeError =
		appointmentDateTimeError?.field === "appointment_time"
			? appointmentDateTimeError.message
			: errors.appointment_time;
	const stepErrors = { ...errors, ...wizardErrors };
	const selectedStore = laboratoryStores.find(
		(store) => String(store.id) === String(data.laboratory_store),
	);
	const isAppointmentConfirmed = Boolean(laboratoryAppointment.confirmed_at);
	const recommendationPresentation =
		appointmentStoreRecommendationPresentation(selectedStoreRecommendation);
	const patientFullName =
		laboratoryAppointment.patient_full_name ||
		[
			data.patient_name,
			data.patient_paternal_lastname,
			data.patient_maternal_lastname,
		]
			.filter(Boolean)
			.join(" ") ||
		"Paciente sin nombre";
	const patientPhone = [data.patient_phone_country, data.patient_phone]
		.filter(Boolean)
		.join(" ");
	const displayStudies =
		studies?.length > 0
			? studies
			: (studyItems ?? []).map((item) => ({
					id: item.id,
					name: item.name,
					gdaId: item.gda_id ?? null,
					description: item.description ?? item.instructions ?? null,
					cost: item.formatted_price ?? null,
					requiresAppointment:
						item.requires_appointment === true ||
						item.requiresAppointment === true,
					status: "pending",
				}));
	const appointmentRequiredStudies = displayStudies.filter(
		(study) =>
			study.requiresAppointment === true ||
			study.requires_appointment === true,
	);
	const studiesCountLabel = `${displayStudies.length} ${
		displayStudies.length === 1 ? "estudio incluido" : "estudios incluidos"
	}`;
	const appointmentRequiredCountLabel =
		appointmentRequiredStudies.length > 0
			? `${appointmentRequiredStudies.length} ${
					appointmentRequiredStudies.length === 1
						? "requiere cita"
						: "requieren cita"
				}`
			: "Ningún estudio requiere cita";
	const appointmentValidationError = validateAppointmentDateTime(
		data.appointment_date,
		data.appointment_time,
	);
	const validatePatientStep = () => {
		const nextErrors = {};

		if (!data.patient_name) {
			nextErrors.patient_name = "Ingresa el nombre del paciente.";
		}

		if (!data.patient_paternal_lastname) {
			nextErrors.patient_paternal_lastname =
				"Ingresa el apellido paterno.";
		}

		if (!data.patient_maternal_lastname) {
			nextErrors.patient_maternal_lastname =
				"Ingresa el apellido materno.";
		}

		if (!data.patient_birth_date) {
			nextErrors.patient_birth_date = "Ingresa la fecha de nacimiento.";
		}

		if (!data.patient_gender) {
			nextErrors.patient_gender = "Selecciona el sexo.";
		}

		if (!data.patient_phone_country || !data.patient_phone) {
			nextErrors.patient_phone = "Ingresa el teléfono de contacto.";
		}

		return nextErrors;
	};
	const validateAppointmentStep = () => {
		const nextErrors = {};

		if (!data.laboratory_store) {
			nextErrors.laboratory_store = "Selecciona una sucursal.";
		}

		if (!data.appointment_date) {
			nextErrors.appointment_date = "Selecciona la fecha de cita.";
		}

		if (!data.appointment_time) {
			nextErrors.appointment_time = "Selecciona la hora de cita.";
		}

		if (appointmentValidationError) {
			nextErrors[appointmentValidationError.field] =
				"No puedes agendar una cita en una fecha y hora que ya pasó.";
		}

		return nextErrors;
	};
	const validateStep = (step) => {
		if (step === 1) {
			return validatePatientStep();
		}

		if (step === 2) {
			return validateAppointmentStep();
		}

		return {};
	};
	const isConfirmDisabled =
		processing ||
		!data.laboratory_store ||
		!data.appointment_date ||
		!data.appointment_time ||
		Boolean(appointmentValidationError);
	const wizardSteps = [
		{
			id: 1,
			name: "Paciente",
			description: "Datos personales",
		},
		{
			id: 2,
			name: "Cita",
			description: "Sucursal y horario",
		},
		{
			id: 3,
			name: "Estudio",
			description: "Revisión",
		},
		{
			id: 4,
			name: "Confirmación",
			description: "Resumen final",
		},
	];
	const currentStepCopy = {
		1: {
			title: "Datos del paciente",
			description:
				"Verifica y edita la información del paciente si es necesario.",
		},
		2: {
			title: "Datos de la cita",
			description: "Selecciona dónde y cuándo se realizará la cita.",
		},
		3: {
			title: "Estudios incluidos",
			description: "Revisa los estudios que se realizarán en la cita.",
		},
		4: {
			title: "Confirmar cita",
			description: "Revisa la información antes de confirmar.",
		},
	}[currentStep];
	const closeDialog = () => {
		setOpenConfirmation(false);
		setCurrentStep(1);
		setWizardErrors({});
	};
	const goToNextStep = () => {
		const nextErrors = validateStep(currentStep);
		setWizardErrors(nextErrors);

		if (Object.keys(nextErrors).length > 0) {
			return;
		}

		setCurrentStep((step) => Math.min(step + 1, 4));
	};
	const goToPreviousStep = () => {
		setWizardErrors({});
		setCurrentStep((step) => Math.max(step - 1, 1));
	};

	return (
		<Dialog open={openConfirmation} onClose={closeDialog} size="4xl">
			<form onSubmit={submit} className="relative">
				<button
					type="button"
					onClick={closeDialog}
					className="absolute right-0 top-0 rounded-full p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
					aria-label="Cerrar"
				>
					<XMarkIcon className="size-5" />
				</button>

				<DialogTitle className="pr-8">Confirmar cita</DialogTitle>
				<DialogDescription>
					Agenda la cita del paciente con el laboratorio.
				</DialogDescription>

				<div className="mt-6 grid gap-3 sm:grid-cols-4">
					{wizardSteps.map((step) => {
						const isCurrent = step.id === currentStep;
						const isCompleted = step.id < currentStep;
						const canSelect = step.id <= currentStep;

						return (
							<button
								key={step.id}
								type="button"
								disabled={!canSelect}
								onClick={() =>
									canSelect && setCurrentStep(step.id)
								}
								className={clsx(
									"group flex min-w-0 items-center gap-3 rounded-xl border p-3 text-left transition",
									isCurrent
										? "border-sky-300 bg-sky-50 dark:border-sky-500/40 dark:bg-sky-500/10"
										: isCompleted
											? "border-emerald-200 bg-emerald-50/60 dark:border-emerald-500/30 dark:bg-emerald-500/10"
											: "border-zinc-200 bg-zinc-50/70 dark:border-zinc-800 dark:bg-zinc-900/50",
									canSelect &&
										"hover:border-sky-300 hover:bg-sky-50/70 dark:hover:border-sky-500/40 dark:hover:bg-sky-500/10",
								)}
							>
								<span
									className={clsx(
										"flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold ring-1",
										isCurrent
											? "bg-sky-600 text-white ring-sky-600"
											: isCompleted
												? "bg-emerald-600 text-white ring-emerald-600"
												: "bg-white text-zinc-500 ring-zinc-200 dark:bg-zinc-950 dark:text-zinc-400 dark:ring-zinc-700",
									)}
								>
									{isCompleted ? (
										<CheckCircleIcon className="size-5" />
									) : (
										step.id
									)}
								</span>
								<span className="min-w-0">
									<span className="block truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">
										{step.name}
									</span>
									<span className="hidden truncate text-xs text-zinc-500 sm:block dark:text-zinc-400">
										{step.description}
									</span>
								</span>
							</button>
						);
					})}
				</div>

				<DialogBody className="min-h-[26rem] space-y-5">
					<header>
						<p className="text-sm font-semibold text-zinc-950 dark:text-white">
							{currentStepCopy.title}
						</p>
						<p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							{currentStepCopy.description}
						</p>
					</header>

					{currentStep === 1 && (
						<section className="space-y-4">
							<div className="grid gap-4 sm:grid-cols-3">
								<Field>
									<Label>Nombre(s) *</Label>
									<Input
										dusk="patientName"
										required
										type="text"
										value={data.patient_name}
										onChange={(e) =>
											setData(
												"patient_name",
												e.target.value,
											)
										}
									/>
									{stepErrors.patient_name && (
										<ErrorMessage>
											{stepErrors.patient_name}
										</ErrorMessage>
									)}
								</Field>
								<Field>
									<Label>Apellido paterno *</Label>
									<Input
										dusk="patientPaternalLastname"
										required
										type="text"
										value={data.patient_paternal_lastname}
										onChange={(e) =>
											setData(
												"patient_paternal_lastname",
												e.target.value,
											)
										}
									/>
									{stepErrors.patient_paternal_lastname && (
										<ErrorMessage>
											{
												stepErrors.patient_paternal_lastname
											}
										</ErrorMessage>
									)}
								</Field>
								<Field>
									<Label>Apellido materno *</Label>
									<Input
										dusk="patientMaternalLastname"
										required
										type="text"
										value={data.patient_maternal_lastname}
										onChange={(e) =>
											setData(
												"patient_maternal_lastname",
												e.target.value,
											)
										}
									/>
									{stepErrors.patient_maternal_lastname && (
										<ErrorMessage>
											{
												stepErrors.patient_maternal_lastname
											}
										</ErrorMessage>
									)}
								</Field>
							</div>

							<div className="grid gap-4 sm:grid-cols-2">
								<Field>
									<Label>Fecha de nacimiento *</Label>
									<Input
										dusk="birthDate"
										required
										type="date"
										value={data.patient_birth_date}
										autoComplete="bday"
										onChange={(e) =>
											setData(
												"patient_birth_date",
												e.target.value,
											)
										}
									/>
									{stepErrors.patient_birth_date && (
										<ErrorMessage>
											{stepErrors.patient_birth_date}
										</ErrorMessage>
									)}
								</Field>
								<Field>
									<Label>Sexo *</Label>
									<Select
										dusk="gender"
										required
										value={data.patient_gender}
										onChange={(e) =>
											setData(
												"patient_gender",
												e.target.value,
											)
										}
									>
										<option value="" disabled>
											Selecciona una opción
										</option>
										{genders.map(({ label, value }) => (
											<option key={value} value={value}>
												{label}
											</option>
										))}
									</Select>
									{stepErrors.patient_gender && (
										<ErrorMessage>
											{stepErrors.patient_gender}
										</ErrorMessage>
									)}
								</Field>
							</div>

							<Field>
								<Label className="inline-flex items-center gap-2">
									<PhoneIcon className="size-4 text-zinc-400" />
									Teléfono de contacto *
								</Label>
								<div
									data-slot="control"
									className="flex flex-1 gap-2"
								>
									<CountryListbox
										setCountry={(e) =>
											setData("patient_phone_country", e)
										}
										country={data.patient_phone_country}
										className="max-w-32"
									/>
									<Input
										dusk="phone"
										required
										value={data.patient_phone}
										onChange={(e) =>
											setData(
												"patient_phone",
												e.target.value,
											)
										}
										type="text"
										autoComplete="tel-national"
									/>
								</div>
								{stepErrors.patient_phone && (
									<ErrorMessage>
										{stepErrors.patient_phone}
									</ErrorMessage>
								)}
							</Field>

							<Field>
								<Label className="inline-flex items-center gap-2">
									<PencilSquareIcon className="size-4 text-zinc-400" />
									Notas para el cliente
								</Label>
								<Textarea
									dusk="notes"
									rows={3}
									value={data.notes}
									onChange={(e) =>
										setData("notes", e.target.value)
									}
								/>
								{stepErrors.notes && (
									<ErrorMessage>
										{stepErrors.notes}
									</ErrorMessage>
								)}
							</Field>
						</section>
					)}

					{currentStep === 2 && (
						<section className="space-y-4">
							{isAppointmentConfirmed ? (
								<ConfirmedLaboratoryStoreCard
									store={laboratoryAppointment.laboratory_store}
								/>
							) : (
								<SelectedStoreRecommendationCard
									recommendation={selectedStoreRecommendation}
									presentation={recommendationPresentation}
									onUse={() => {
										if (
											selectedStoreRecommendation?.store
												?.id
										) {
											setData(
												"laboratory_store",
												String(
													selectedStoreRecommendation
														.store.id,
												),
											);
										}
									}}
								/>
							)}

							<Field>
								<Label className="inline-flex items-center gap-2">
									<BuildingOffice2Icon className="size-4 text-zinc-400" />
									Sucursal *
								</Label>
								<Select
									dusk="laboratoryStore"
									required
									value={data.laboratory_store}
									onChange={(e) =>
										setData(
											"laboratory_store",
											e.target.value,
										)
									}
								>
									<option value="" disabled>
										Selecciona una opción
									</option>
									{laboratoryStores.map((store) => (
										<option key={store.id} value={store.id}>
											{store.name}
										</option>
									))}
								</Select>
								{stepErrors.laboratory_store && (
									<ErrorMessage>
										{stepErrors.laboratory_store}
									</ErrorMessage>
								)}
							</Field>

							{selectedStore?.address && (
								<div className="rounded-lg border border-zinc-200/70 bg-zinc-50/70 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/50">
									<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
										Dirección
									</p>
									<p
										className="mt-1 line-clamp-2 text-sm text-zinc-700 dark:text-zinc-300"
										title={selectedStore.address}
									>
										{selectedStore.address}
									</p>
								</div>
							)}

							<div className="grid gap-4 sm:grid-cols-2">
								<Field>
									<Label className="inline-flex items-center gap-2">
										<CalendarDaysIcon className="size-4 text-zinc-400" />
										Fecha de cita *
									</Label>
									<Input
										dusk="appointmentDate"
										required
										type="date"
										min={minimumAppointmentDate}
										value={data.appointment_date}
										onChange={(e) =>
											handleAppointmentDateChange(
												e.target.value,
											)
										}
									/>
									{(stepErrors.appointment_date ||
										appointmentDateError) && (
										<ErrorMessage>
											{stepErrors.appointment_date ||
												appointmentDateError}
										</ErrorMessage>
									)}
								</Field>
								<Field>
									<Label className="inline-flex items-center gap-2">
										<ClockIcon className="size-4 text-zinc-400" />
										Hora de cita *
									</Label>
									<Input
										dusk="appointmentTime"
										required
										type="time"
										min={minimumAppointmentTime}
										value={data.appointment_time}
										onChange={(e) =>
											handleAppointmentTimeChange(
												e.target.value,
											)
										}
									/>
									{(stepErrors.appointment_time ||
										appointmentTimeError) && (
										<ErrorMessage>
											{stepErrors.appointment_time ||
												appointmentTimeError}
										</ErrorMessage>
									)}
								</Field>
							</div>
						</section>
					)}

					{currentStep === 3 && (
						<section className="space-y-3">
								{displayStudies.length === 0 ? (
									<div className="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
										Sin estudios asociados.
									</div>
								) : (
									displayStudies.map((study, index) => {
										const requiresAppointment =
											study.requiresAppointment === true ||
											study.requires_appointment === true;

										return (
											<div
												key={study.id ?? study.name}
												className="rounded-xl border border-zinc-200/70 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50"
											>
												<div className="flex gap-3">
													<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
														<BeakerIcon className="size-5" />
													</div>
													<div className="min-w-0 flex-1">
														<div className="flex flex-wrap items-start justify-between gap-2">
															<p className="min-w-0 flex-1 text-sm font-semibold uppercase text-zinc-950 dark:text-white">
															<span className="mr-1 text-zinc-400">
																{index + 1}.
															</span>
															{study.name}
														</p>
														{requiresAppointment && (
															<Badge color="amber">
																Requiere cita
															</Badge>
														)}
													</div>
														<div className="mt-2 flex flex-wrap gap-2 text-xs">
															<Badge color="zinc">
																GDA{" "}
																{study.gdaId ??
																	"Sin ID"}
															</Badge>
															<Badge color="zinc">
																{study.cost ??
																	"Sin costo"}
															</Badge>
														</div>
													</div>
												</div>
											</div>
										);
									})
								)}
							</section>
						)}

					{currentStep === 4 && (
						<section className="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200/70 bg-zinc-50/70 dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900/50">
							<ConfirmationSummarySection
								icon={UserIcon}
								title="Paciente"
								lines={[
									patientFullName,
									patientPhone || "Sin teléfono",
								]}
							/>
							<ConfirmationSummarySection
								icon={CalendarDaysIcon}
								title="Cita"
								lines={[
									laboratoryAppointment.brand?.toUpperCase() ??
										"Laboratorio",
									selectedStore?.name ?? "Sin sucursal",
									formatReadableDate(data.appointment_date, {
										month: "long",
									}),
									formatReadableTime(data.appointment_time),
								]}
							/>
								<ConfirmationSummarySection
									icon={BeakerIcon}
									title="Estudios"
									lines={[
										studiesCountLabel,
										appointmentRequiredCountLabel,
									]}
									items={displayStudies.map((study, index) => ({
										label: `${index + 1}. ${study.name}`,
										detail: [
											study.gdaId
												? `GDA ${study.gdaId}`
												: null,
											study.cost,
										]
											.filter(Boolean)
											.join(" · "),
										badge:
											study.requiresAppointment === true ||
											study.requires_appointment === true
												? "Requiere cita"
												: null,
									}))}
								/>
							{hasPaidLaboratoryPurchase ? (
								<div className="p-4">
									<CheckboxField>
										<Checkbox
											color="sky"
											checked={
												data.send_notification_email
											}
											onChange={(value) =>
												setData(
													"send_notification_email",
													value,
												)
											}
										/>
										<Label>
											Enviar correo de notificación al
											paciente
										</Label>
										<Description>
											Se enviará un correo con la
											confirmación o actualización de su
											cita.
										</Description>
									</CheckboxField>
								</div>
							) : (
								<div className="p-4 text-sm text-amber-900 dark:text-amber-100">
									Al confirmar, se enviará automáticamente un
									correo al cliente con el resumen de la cita
									y un enlace para completar el pago en el
									checkout.
								</div>
							)}
						</section>
					)}
				</DialogBody>

				<DialogActions>
					{currentStep === 1 ? (
						<Button
							plain
							type="button"
							onClick={(event) => {
								event.preventDefault();
								event.stopPropagation();
								closeDialog();
							}}
							disabled={processing}
							autoFocus
						>
							Cancelar
						</Button>
					) : (
						<Button
							plain
							type="button"
							onClick={(event) => {
								event.preventDefault();
								event.stopPropagation();
								goToPreviousStep();
							}}
							disabled={processing}
						>
							← Anterior
						</Button>
					)}

					{currentStep < 4 ? (
						<Button
							type="button"
							onClick={(event) => {
								event.preventDefault();
								event.stopPropagation();
								goToNextStep();
							}}
							disabled={processing}
						>
							Siguiente →
						</Button>
					) : (
						<Button
							dusk="saveLaboratoryAppointment"
							type="submit"
							disabled={isConfirmDisabled}
						>
							Confirmar cita
							{processing && (
								<ArrowPathIcon className="animate-spin" />
							)}
						</Button>
					)}
				</DialogActions>
			</form>
		</Dialog>
	);
}

function ConfirmedLaboratoryStoreCard({ store }) {
	if (!store) {
		return null;
	}

	return (
		<div className="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
			<div className="flex min-w-0 gap-3">
				<span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-200 dark:ring-emerald-500/30">
					<CheckCircleIcon className="size-5" />
				</span>
				<div className="min-w-0">
					<p className="text-sm font-semibold">
						Sucursal confirmada
					</p>
					<p className="mt-1 text-sm leading-6">
						Esta es la sucursal definida para la cita.
					</p>
					<div className="mt-3 rounded-lg bg-white/70 px-3 py-2 text-sm text-zinc-800 ring-1 ring-black/5 dark:bg-zinc-950/40 dark:text-zinc-100 dark:ring-white/10">
						<p className="font-semibold">{store.name}</p>
						<p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
							{store.brand_label ?? store.brand}
						</p>
						{store.address && (
							<p className="mt-1 line-clamp-2 text-xs text-zinc-600 dark:text-zinc-300">
								{store.address}
							</p>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}

function SelectedStoreRecommendationCard({
	recommendation,
	presentation,
	onUse,
}) {
	if (!recommendation?.store || !presentation) {
		return null;
	}

	const store = recommendation.store;
	const isSuccess = presentation.tone === "success";
	const containerClass = isSuccess
		? "border-emerald-200 bg-emerald-50/70 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100"
		: "border-amber-200 bg-amber-50/70 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100";
	const iconClass = isSuccess
		? "bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-200 dark:ring-emerald-500/30"
		: "bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-500/20 dark:text-amber-200 dark:ring-amber-500/30";

	return (
		<div className={clsx("rounded-xl border p-4", containerClass)}>
			<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div className="flex min-w-0 gap-3">
					<span
						className={clsx(
							"flex size-9 shrink-0 items-center justify-center rounded-full ring-1",
							iconClass,
						)}
					>
						<BuildingOffice2Icon className="size-5" />
					</span>
					<div className="min-w-0">
						<p className="text-sm font-semibold">
							{presentation.title}
						</p>
						<p className="mt-1 text-sm leading-6">
							{presentation.message}
						</p>
						<div className="mt-3 rounded-lg bg-white/70 px-3 py-2 text-sm text-zinc-800 ring-1 ring-black/5 dark:bg-zinc-950/40 dark:text-zinc-100 dark:ring-white/10">
							<p className="font-semibold">{store.name}</p>
							<p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
								{store.brand_label ?? store.brand}
							</p>
							{store.address && (
								<p className="mt-1 line-clamp-2 text-xs text-zinc-600 dark:text-zinc-300">
									{store.address}
								</p>
							)}
						</div>
						<p className="mt-2 text-xs leading-5">
							{presentation.detail}
						</p>
					</div>
				</div>

				{presentation.canUse && (
					<Button
						type="button"
						outline
						className="shrink-0 justify-center"
						onClick={onUse}
					>
						<CheckCircleIcon />
						{presentation.actionLabel}
					</Button>
				)}
			</div>
		</div>
	);
}

function ConfirmationSummarySection({ icon: Icon, title, lines, items = [] }) {
	return (
		<div className="flex gap-3 p-4">
			<div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
				<Icon className="size-4" />
			</div>
			<div className="min-w-0">
				<p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
					✓ {title}
				</p>
				<div className="mt-1 space-y-0.5">
					{lines.filter(Boolean).map((line) => (
						<p
							key={line}
							className="line-clamp-2 text-sm text-zinc-700 first:font-semibold first:text-zinc-950 dark:text-zinc-300 dark:first:text-white"
						>
							{line}
						</p>
					))}
				</div>
				{items.length > 0 && (
					<ul className="mt-3 space-y-2">
						{items.map((item) => (
							<li
								key={item.label}
								className="rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-950"
							>
								<div className="flex flex-wrap items-start justify-between gap-2">
									<div className="min-w-0">
										<p className="text-sm font-medium text-zinc-950 dark:text-white">
											{item.label}
										</p>
										{item.detail && (
											<p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
												{item.detail}
											</p>
										)}
									</div>
									{item.badge && (
										<Badge color="amber">{item.badge}</Badge>
									)}
								</div>
							</li>
						))}
					</ul>
				)}
			</div>
		</div>
	);
}
