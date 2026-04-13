import { useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import {
	ArrowPathIcon,
	BuildingStorefrontIcon,
	CalendarIcon,
	EnvelopeIcon,
	PhoneIcon,
	ClockIcon,
	ChatBubbleLeftRightIcon,
} from "@heroicons/react/16/solid";
import {
	CalendarDaysIcon,
	TrashIcon,
	PencilIcon,
} from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Anchor, Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
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
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import CountryListbox from "@/Components/CountryListbox";
import StatusBadge from "@/Components/StatusBadge";
import Flag from "react-flagpack";
import clsx from "clsx";

const interactionTypeLabels = {
	patient_phone_intent: "Intento de llamada (paciente)",
	patient_callback_preference: "Preferencia de contacto (paciente)",
	concierge_note: "Nota del concierge",
	concierge_outbound_call: "Llamada saliente (concierge)",
};

export default function LaboratoryAppointment({
	laboratoryAppointment,
	laboratoryStores,
	laboratoryCartItems,
	interactions,
}) {
	const [patientView, setPatientView] = useState("none");

	return (
		<AdminLayout title="Cita de laboratorio">
			<div className="w-40">
				<LaboratoryBrandCard
					src={
						"/images/gda/GDA-" +
						laboratoryAppointment.brand.toUpperCase() +
						".png"
					}
				/>
			</div>

			<Header
				laboratoryAppointment={laboratoryAppointment}
				laboratoryStores={laboratoryStores}
				patientView={patientView}
				setPatientView={setPatientView}
			/>

			<Patient
				laboratoryAppointment={laboratoryAppointment}
				laboratoryCartItems={laboratoryCartItems}
			/>

			<Contact laboratoryAppointment={laboratoryAppointment} />

			<PatientCommunicationTabs
				laboratoryAppointment={laboratoryAppointment}
				interactions={interactions}
				patientView={patientView}
			/>

			<LaboratoryAppointmentConfirmation
				laboratoryAppointment={laboratoryAppointment}
			/>
		</AdminLayout>
	);
}

function PatientCommunicationTabs({
	laboratoryAppointment,
	interactions,
	patientView,
}) {
	if (patientView === "none") {
		return null;
	}

	return (
		<div className="mt-10">
			{patientView === "followup" ? (
				<>
					<PatientFollowUp laboratoryAppointment={laboratoryAppointment} />
					<RequestTimeline laboratoryAppointment={laboratoryAppointment} />
				</>
			) : (
				<InteractionBitacora
					interactions={interactions}
					laboratoryAppointment={laboratoryAppointment}
				/>
			)}
		</div>
	);
}

function RequestTimeline({ laboratoryAppointment }) {
	const timelineSteps = [
		{
			label: "Solicitud",
			value: laboratoryAppointment.formatted_created_at,
		},
		{
			label: "Intento de llamada",
			value: laboratoryAppointment.formatted_phone_call_intent_at,
		},
		{
			label: "Confirmación",
			value: laboratoryAppointment.formatted_confirmed_at,
		},
		{
			label: "Compra",
			value:
				laboratoryAppointment.formatted_purchase_at ??
				laboratoryAppointment.formatted_purchased_at ??
				laboratoryAppointment.purchase?.formatted_created_at,
		},
	];

	return (
		<div className="mt-10">
			<Subheading>Línea de tiempo</Subheading>
			<Text className="mt-1 text-sm text-zinc-500">
				Simulación de hitos principales de la cita y su seguimiento.
			</Text>

			<ol className="mt-4 space-y-4 border-l-2 border-zinc-200 pl-5 dark:border-zinc-700">
				{timelineSteps.map((step) => (
					<li key={step.label} className="relative">
						<span className="absolute -left-[1.75rem] mt-1 size-3 rounded-full bg-sky-500" />
						<Text className="font-semibold">{step.label}</Text>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							{step.value ?? "Pendiente"}
						</Text>
					</li>
				))}
			</ol>
		</div>
	);
}

function PatientFollowUp({ laboratoryAppointment }) {
	return (
		<div className="mt-10">
			<Subheading>Seguimiento con el paciente</Subheading>
			<Text className="mt-1 text-sm text-zinc-500">
				Tiempos relativos a la solicitud de cita y a la interacción con
				el teléfono del laboratorio.
			</Text>

			<DescriptionList className="mt-4">
				<DescriptionTerm>Tiempo desde la solicitud</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.time_since_request_human}
				</DescriptionDetails>

				<DescriptionTerm>Intentó llamar al concierge</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.formatted_phone_call_intent_at ? (
						<span className="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
							<span>
								{laboratoryAppointment.formatted_phone_call_intent_at}
							</span>
							{laboratoryAppointment.time_since_phone_intent_human && (
								<Badge color="slate">
									{laboratoryAppointment.time_since_phone_intent_human}
								</Badge>
							)}
						</span>
					) : (
						"…"
					)}
				</DescriptionDetails>

				<DescriptionTerm>Disponibilidad para recibir llamada</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.formatted_callback_availability_range ??
						"…"}
				</DescriptionDetails>

				<DescriptionTerm>Comentarios del paciente</DescriptionTerm>
				<DescriptionDetails>
					<span className="block max-w-xl whitespace-pre-wrap">
						{laboratoryAppointment.patient_callback_comment ?? "…"}
					</span>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function InteractionBitacora({ interactions, laboratoryAppointment }) {
	const { data, setData, post, processing, errors, reset } = useForm({
		type: "concierge_outbound_call",
		body: "",
	});

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			post(
				route("admin.laboratory-appointments.interactions.store", {
					laboratory_appointment: laboratoryAppointment.id,
				}),
				{
					preserveScroll: true,
					onSuccess: () => reset("body"),
				},
			);
		}
	};

	return (
		<div className="mt-10">
			<Subheading>Bitácora de interacciones</Subheading>
			<Text className="mt-1 text-sm text-zinc-500">
				Registro cronológico de intentos del paciente y notas del equipo
				concierge.
			</Text>

			<ul className="mt-6 space-y-4">
				{interactions.length === 0 ? (
					<Text className="text-zinc-500">Sin registros aún.</Text>
				) : (
					interactions.map((row) => (
						<li
							key={row.id}
							className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
						>
							<div className="flex flex-wrap items-center gap-2">
								<ChatBubbleLeftRightIcon className="size-4 text-zinc-400" />
								<Badge color="sky">
									{interactionTypeLabels[row.type] ?? row.type}
								</Badge>
								<Text className="text-xs text-zinc-500">
									{new Date(row.created_at).toLocaleString("es-MX", {
										dateStyle: "medium",
										timeStyle: "short",
									})}
								</Text>
								{row.admin_user && (
									<Badge color="slate">
										{row.admin_user.name}
									</Badge>
								)}
							</div>
							{row.body && (
								<Text className="mt-2 whitespace-pre-wrap text-sm">
									{row.body}
								</Text>
							)}
						</li>
					))
				)}
			</ul>

			<form
				onSubmit={submit}
				className="mt-8 max-w-xl space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
			>
				<Field>
					<Label>Registrar seguimiento</Label>
					<Select
						value={data.type}
						onChange={(e) => setData("type", e.target.value)}
					>
						<option value="concierge_outbound_call">
							Llamada saliente al paciente
						</option>
						<option value="concierge_note">Nota interna</option>
					</Select>
					<Description>
						Queda guardado con tu usuario y la fecha actual.
					</Description>
				</Field>
				<Field>
					<Label>Detalle</Label>
					<Textarea
						rows={4}
						value={data.body}
						onChange={(e) => setData("body", e.target.value)}
						placeholder="Ej. Paciente no contestó; dejar mensaje en buzón…"
						required
					/>
					{errors.body && (
						<ErrorMessage>{errors.body}</ErrorMessage>
					)}
				</Field>
				<div className="flex justify-end">
					<Button type="submit" disabled={processing}>
						{processing ? "Guardando…" : "Agregar a la bitácora"}
					</Button>
				</div>
			</form>
		</div>
	);
}

function Header({
	laboratoryAppointment,
	laboratoryStores,
	patientView,
	setPatientView,
}) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);
	const [openConfirmation, setOpenConfirmation] = useState(false);

	return (
		<div>
			<div className="flex flex-wrap items-center gap-4">
				<Heading>
					Cita de{" "}
					{laboratoryAppointment.patient_name ||
						laboratoryAppointment.customer.user.full_name}
				</Heading>
				<StatusBadge isActive={laboratoryAppointment.confirmed_at} />
			</div>
			<div className="isolate mt-2.5 flex flex-wrap justify-between gap-x-6 gap-y-4">
				{laboratoryAppointment.confirmed_at && (
					<div className="flex flex-wrap gap-x-10 gap-y-4 py-1.5">
						<span className="flex items-center gap-3 text-base/6 text-zinc-950 sm:text-sm/6 dark:text-white">
							<BuildingStorefrontIcon className="size-4 shrink-0 fill-zinc-400 dark:fill-zinc-500" />
							<span>
								{laboratoryAppointment.laboratory_store?.name}
							</span>
						</span>
						<span className="flex items-center gap-3 text-base/6 text-zinc-950 sm:text-sm/6 dark:text-white">
							<CalendarIcon className="size-4 shrink-0 fill-zinc-400 dark:fill-zinc-500" />
							<span>
								{
									laboratoryAppointment.formatted_appointment_date
								}
							</span>
						</span>
					</div>
				)}
				<div className="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
					<Button
						type="button"
						onClick={() => setPatientView("followup")}
						outline={patientView !== "followup"}
					>
						<ClockIcon />
						Seguimiento y línea de tiempo
					</Button>
					<Button
						type="button"
						onClick={() => setPatientView("bitacora")}
						outline={patientView !== "bitacora"}
					>
						<ChatBubbleLeftRightIcon />
						Bitácora de interacciones
					</Button>
					{!laboratoryAppointment.confirmed_at && (
						<LaboratoryAppointmentDeleteForm
							laboratoryAppointment={laboratoryAppointment}
							setOpenDeleteConfirmation={
								setOpenDeleteConfirmation
							}
							openDeleteConfirmation={openDeleteConfirmation}
						/>
					)}
					<LaboratoryAppointmentConfirmationForm
						laboratoryAppointment={laboratoryAppointment}
						laboratoryStores={laboratoryStores}
						setOpenConfirmation={setOpenConfirmation}
						openConfirmation={openConfirmation}
					/>
				</div>
			</div>
		</div>
	);
}

function LaboratoryAppointmentDeleteForm({
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
		<>
			<Button
				outline
				dusk="deleteLaboratoryAppointment"
				onClick={() => setOpenDeleteConfirmation(true)}
			>
				<TrashIcon className="stroke-red-400" />
				Eliminar
			</Button>

			<DeleteConfirmationModal
				isOpen={!!openDeleteConfirmation}
				close={() => setOpenDeleteConfirmation(false)}
				title="Eliminar cita"
				description="¿Estás seguro de que deseas eliminar esta cita?"
				processing={processing}
				destroy={deleteLaboratoryAppointment}
			/>
		</>
	);
}

function LaboratoryAppointmentConfirmationForm({
	laboratoryAppointment,
	laboratoryStores,
	setOpenConfirmation,
	openConfirmation,
}) {
	const { genders } = usePage().props;

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
	});

	const submit = (e) => {
		e.preventDefault();

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

	return (
		<>
			<Button
				onClick={() => setOpenConfirmation(true)}
				{...(laboratoryAppointment.confirmed_at && { outline: true })}
			>
				{laboratoryAppointment.confirmed_at ? (
					<PencilIcon />
				) : (
					<CalendarDaysIcon />
				)}
				{laboratoryAppointment.confirmed_at ? "Editar cita" : "Agendar"}
			</Button>

			<Dialog open={openConfirmation} onClose={setOpenConfirmation}>
				<form onSubmit={submit}>
					<DialogTitle>Confirmar cita</DialogTitle>
					<DialogDescription>
						Ingresa la información de la cita.
					</DialogDescription>
					<DialogBody className="space-y-6">
						<Field>
							<Label>Sucursal</Label>
							<Select
								dusk="laboratoryStore"
								required
								value={data.laboratory_store}
								onChange={(e) =>
									setData("laboratory_store", e.target.value)
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
							{data.laboratory_store && (
								<Description>
									{
										laboratoryStores.find(
											(store) =>
												store.id ==
												data.laboratory_store,
										)?.address
									}
								</Description>
							)}
							{errors.laboratory_store && (
								<ErrorMessage>
									{errors.laboratory_store}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Fecha de cita</Label>
							<Input
								dusk="appointmentDate"
								required
								type="date"
								value={data.appointment_date}
								onChange={(e) =>
									setData("appointment_date", e.target.value)
								}
							/>
							{errors.appointment_date && (
								<ErrorMessage>
									{errors.appointment_date}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Hora de cita</Label>
							<Input
								dusk="appointmentTime"
								required
								type="time"
								value={data.appointment_time}
								onChange={(e) =>
									setData("appointment_time", e.target.value)
								}
							/>
							{errors.appointment_time && (
								<ErrorMessage>
									{errors.appointment_time}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Nombre(s) del paciente</Label>
							<Input
								dusk="patientName"
								required
								type="text"
								value={data.patient_name}
								onChange={(e) =>
									setData("patient_name", e.target.value)
								}
							/>
							{errors.patient_name && (
								<ErrorMessage>
									{errors.patient_name}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Apellido paterno del paciente</Label>
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
							{errors.patient_paternal_lastname && (
								<ErrorMessage>
									{errors.patient_paternal_lastname}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Apellido materno del paciente</Label>
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
							{errors.patient_maternal_lastname && (
								<ErrorMessage>
									{errors.patient_maternal_lastname}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Teléfono de contacto</Label>
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
										setData("patient_phone", e.target.value)
									}
									type="text"
									autoComplete="tel-national"
								/>
							</div>
							{errors.patient_phone && (
								<ErrorMessage>
									{errors.patient_phone}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Fecha de nacimiento</Label>
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
							{errors.patient_birth_date && (
								<ErrorMessage>
									{errors.patient_birth_date}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Sexo</Label>
							<Select
								dusk="gender"
								required
								value={data.patient_gender}
								onChange={(e) =>
									setData("patient_gender", e.target.value)
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
							{errors.patient_gender && (
								<ErrorMessage>
									{errors.patient_gender}
								</ErrorMessage>
							)}
						</Field>

						<Field>
							<Label>Notas para el cliente</Label>
							<Textarea
								dusk="notes"
								value={data.notes}
								onChange={(e) =>
									setData("notes", e.target.value)
								}
							/>
							{errors.notes && (
								<ErrorMessage>{errors.notes}</ErrorMessage>
							)}
						</Field>
					</DialogBody>
					<DialogActions>
						<Button
							plain
							onClick={() => setOpenConfirmation(false)}
							disabled={processing}
							autoFocus
						>
							Cancelar
						</Button>
						<Button
							dusk="saveLaboratoryAppointment"
							type="submit"
							disabled={processing}
						>
							Guardar
							{processing && (
								<ArrowPathIcon className="animate-spin" />
							)}
						</Button>
					</DialogActions>
				</form>
			</Dialog>
		</>
	);
}

function Patient({ laboratoryAppointment, laboratoryCartItems }) {
	return (
		<div>
			<Subheading>Información del paciente</Subheading>

			<DescriptionList>
				<DescriptionTerm>Paciente</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.patient_full_name ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Sexo</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.formatted_patient_gender}
				</DescriptionDetails>
				<DescriptionTerm>Teléfono</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.patient_full_phone ? (
						<Anchor
							href={`tel:${laboratoryAppointment.patient_full_phone}`}
						>
							<Button outline>
								<PhoneIcon />
								<Flag
									className="shrink-0"
									code={
										laboratoryAppointment.patient_phone_country
									}
									size="s"
								/>
								{laboratoryAppointment.patient_phone}
							</Button>
						</Anchor>
					) : (
						"..."
					)}
				</DescriptionDetails>
				<DescriptionTerm>Fecha de nacimiento</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.formatted_patient_birth_date ??
						"..."}
				</DescriptionDetails>

				<DescriptionTerm>Estudios </DescriptionTerm>
				<DescriptionDetails>
					{laboratoryCartItems.length > 0 ? (
						<div className="flex flex-col gap-1">
							{laboratoryCartItems.map((cartItem) => (
								<span key={cartItem.id}>
									<Badge color="slate">
										{cartItem.laboratory_test
											.requires_appointment && (
											<CalendarDaysIcon
												className={`size-4 flex-shrink-0 ${laboratoryAppointment.confirmed_at ? "stroke-green-500" : "stroke-red-500"}`}
											/>
										)}
										{cartItem.laboratory_test.name}
									</Badge>
								</span>
							))}
						</div>
					) : (
						"..."
					)}
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function Contact({ laboratoryAppointment }) {
	return (
		<div>
			<Subheading>Información de contacto</Subheading>

			<DescriptionList>
				<DescriptionTerm>Nombre</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.customer.user.full_name}
				</DescriptionDetails>
				<DescriptionTerm>
					Correo electrónico de la cuenta
				</DescriptionTerm>
				<DescriptionDetails>
					<Anchor
						href={`mailto:${laboratoryAppointment.customer.user.email}`}
					>
						<Button outline>
							<EnvelopeIcon />
							{laboratoryAppointment.customer.user.email}
						</Button>
					</Anchor>
				</DescriptionDetails>
				<DescriptionTerm>Teléfono</DescriptionTerm>
				<DescriptionDetails>
					<Anchor
						href={`tel:${laboratoryAppointment.customer.user.full_phone}`}
					>
						<Button outline>
							<PhoneIcon />
							<Flag
								className="shrink-0"
								code={
									laboratoryAppointment.customer.user
										.phone_country
								}
								size="s"
							/>
							{laboratoryAppointment.customer.user.phone}
						</Button>
					</Anchor>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function LaboratoryAppointmentConfirmation({ laboratoryAppointment }) {
	return (
		<div>
			<Subheading>Confirmación de cita</Subheading>

			<DescriptionList>
				<DescriptionTerm>Solicitada</DescriptionTerm>
				<DescriptionDetails className="flex items-center gap-2">
					<ClockIcon className="size-5 fill-zinc-500 sm:size-4" />
					{laboratoryAppointment.formatted_created_at}
				</DescriptionDetails>
				<DescriptionTerm>Fecha de cita</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.formatted_appointment_date ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Sucursal</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryAppointment.laboratory_store?.name ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Dirección</DescriptionTerm>
				<DescriptionDetails>
					<span className="block max-w-48">
						{laboratoryAppointment.laboratory_store?.address ??
							"..."}
					</span>
				</DescriptionDetails>
				<DescriptionTerm>
					Notas compartidas con el cliente
				</DescriptionTerm>
				<DescriptionDetails>
					<span className="block max-w-80">
						{laboratoryAppointment.notes
							? laboratoryAppointment.notes
							: "..."}
					</span>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}
