import { useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import {
	ArrowPathIcon,
	BuildingStorefrontIcon,
	CalendarIcon,
	EnvelopeIcon,
	PhoneIcon,
	ClockIcon,
} from "@heroicons/react/16/solid";
import {
	CalendarDaysIcon,
	TrashIcon,
	PencilIcon,
} from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Anchor } from "@/Components/Catalyst/text";
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

export default function LaboratoryAppointment({
	laboratoryAppointment,
	laboratoryStores,
	laboratoryCartItems,
}) {
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
			/>

			<Patient
				laboratoryAppointment={laboratoryAppointment}
				laboratoryCartItems={laboratoryCartItems}
			/>

			<Contact laboratoryAppointment={laboratoryAppointment} />

			<LaboratoryAppointmentConfirmation
				laboratoryAppointment={laboratoryAppointment}
			/>
		</AdminLayout>
	);
}

function Header({ laboratoryAppointment, laboratoryStores }) {
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
				<div className="flex gap-4">
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
