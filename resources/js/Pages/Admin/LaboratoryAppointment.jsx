import { useMemo, useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import {
	CalendarDaysIcon,
	TrashIcon,
	BuildingOffice2Icon,
	ClockIcon,
	UserIcon,
	PhoneIcon,
	PencilSquareIcon,
} from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
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
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import CountryListbox from "@/Components/CountryListbox";
import AppointmentHeader from "@/Pages/Admin/LaboratoryAppointment/AppointmentHeader";
import PurchaseStatusCard from "@/Pages/Admin/LaboratoryAppointment/PurchaseStatusCard";
import PatientInfoCard from "@/Pages/Admin/LaboratoryAppointment/PatientInfoCard";
import ContactInfoCard from "@/Pages/Admin/LaboratoryAppointment/ContactInfoCard";
import StudiesTable from "@/Pages/Admin/LaboratoryAppointment/StudiesTable";
import AppointmentSidebar from "@/Pages/Admin/LaboratoryAppointment/AppointmentSidebar";

export default function LaboratoryAppointment({
	laboratoryAppointment,
	laboratoryStores,
	laboratoryCartItems,
}) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);
	const [openConfirmation, setOpenConfirmation] = useState(false);

	const appointmentStatus = laboratoryAppointment.deleted_at
		? "cancelled"
		: laboratoryAppointment.confirmed_at
			? "completed"
			: "pending";

	const purchase = laboratoryAppointment.laboratory_purchase ?? null;
	const primaryTransaction = purchase?.transactions?.[0] ?? null;
	const hasCapturedPayment = ["captured", "paid", "completed"].includes(
		primaryTransaction?.payment_status,
	);
	const purchaseStatus = hasCapturedPayment || laboratoryAppointment.laboratory_purchase_id
		? "paid"
		: "pending";

	const studies = useMemo(() => {
		if (
			laboratoryAppointment.laboratory_purchase?.laboratory_purchase_items?.length
		) {
			const purchase = laboratoryAppointment.laboratory_purchase;
			const baseStatus = purchase.has_results_available ? "completed" : "pending";

			return purchase.laboratory_purchase_items.map((item) => ({
				id: item.id,
				name: item.name,
				status: baseStatus,
				sampleType: "Muestra sanguinea",
				performedAt: purchase.formatted_results_at ?? null,
			}));
		}

		return laboratoryCartItems.map((cartItem) => ({
			id: cartItem.id,
			name: cartItem.laboratory_test.name,
			status: laboratoryAppointment.confirmed_at ? "pending" : "pending",
			sampleType: cartItem.laboratory_test.requires_appointment
				? "En laboratorio"
				: "A domicilio",
			performedAt: null,
		}));
	}, [laboratoryAppointment, laboratoryCartItems]);

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
		channel: "Plataforma",
		totalTime: laboratoryAppointment.formatted_created_at ?? "...",
	};

	return (
		<AdminLayout title="Cita de laboratorio">
			<div className="space-y-6">
				<AppointmentHeader
					appointment={laboratoryAppointment}
					status={appointmentStatus}
					showEditButton={false}
					actions={
						<>
							{!laboratoryAppointment.confirmed_at && (
								<LaboratoryAppointmentDeleteForm
									laboratoryAppointment={laboratoryAppointment}
									setOpenDeleteConfirmation={setOpenDeleteConfirmation}
									openDeleteConfirmation={openDeleteConfirmation}
								/>
							)}
							<LaboratoryAppointmentConfirmationForm
								laboratoryAppointment={laboratoryAppointment}
								laboratoryStores={laboratoryStores}
								setOpenConfirmation={setOpenConfirmation}
								openConfirmation={openConfirmation}
								triggerButtonLabel={
									laboratoryAppointment.confirmed_at
										? "Editar cita"
										: "Agendar"
								}
							/>
						</>
					}
				/>

				<PurchaseStatusCard
					purchaseStatus={purchaseStatus}
					orderNumber={purchase?.gda_order_id ?? primaryTransaction?.reference_id}
					purchaseDate={
						purchase?.formatted_created_at ??
						primaryTransaction?.formatted_created_at ??
						null
					}
					studies={studies}
				/>

				<div className="grid gap-6 lg:grid-cols-10">
					<div className="space-y-4 lg:col-span-7">
						<PatientInfoCard patient={patient} />
						<ContactInfoCard contact={contact} />
						<StudiesTable studies={studies} />
					</div>
					<div className="lg:col-span-3">
						<AppointmentSidebar
							appointment={laboratoryAppointment}
							summary={summary}
						/>
					</div>
				</div>
			</div>

		</AdminLayout>
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
	triggerButtonLabel = null,
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
		send_notification_email: true,
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
				<CalendarDaysIcon />
				{triggerButtonLabel ??
					(laboratoryAppointment.confirmed_at ? "Editar cita" : "Agendar")}
			</Button>

			<Dialog open={openConfirmation} onClose={setOpenConfirmation} size="5xl">
				<form onSubmit={submit}>
					<DialogTitle>Confirmar cita</DialogTitle>
					<DialogDescription>
						Ingresa la información de la cita.
					</DialogDescription>
					<DialogBody className="max-h-[70vh] overflow-y-auto">
						<div className="grid gap-4 lg:grid-cols-2">
							<section className="space-y-4 rounded-xl border border-zinc-200/70 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
								<p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									<CalendarDaysIcon className="size-4" />
									Datos de la cita
								</p>
								<Field>
									<Label className="inline-flex items-center gap-2">
										<BuildingOffice2Icon className="size-4 text-zinc-400" />
										Sucursal
									</Label>
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
														store.id == data.laboratory_store,
												)?.address
											}
										</Description>
									)}
									{errors.laboratory_store && (
										<ErrorMessage>{errors.laboratory_store}</ErrorMessage>
									)}
								</Field>
								<div className="grid gap-4 sm:grid-cols-2">
									<Field>
										<Label className="inline-flex items-center gap-2">
											<CalendarDaysIcon className="size-4 text-zinc-400" />
											Fecha de cita
										</Label>
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
											<ErrorMessage>{errors.appointment_date}</ErrorMessage>
										)}
									</Field>
									<Field>
										<Label className="inline-flex items-center gap-2">
											<ClockIcon className="size-4 text-zinc-400" />
											Hora de cita
										</Label>
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
											<ErrorMessage>{errors.appointment_time}</ErrorMessage>
										)}
									</Field>
								</div>
							</section>

							<section className="space-y-4 rounded-xl border border-zinc-200/70 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
								<p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									<UserIcon className="size-4" />
									Datos del paciente
								</p>
								<Field>
									<Label className="inline-flex items-center gap-2">
										<UserIcon className="size-4 text-zinc-400" />
										Nombre(s) del paciente
									</Label>
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
										<ErrorMessage>{errors.patient_name}</ErrorMessage>
									)}
								</Field>
								<div className="grid gap-4 sm:grid-cols-2">
									<Field>
										<Label>Apellido paterno</Label>
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
										<Label>Apellido materno</Label>
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
								</div>
								<div className="grid gap-4 sm:grid-cols-2">
									<Field>
										<Label>Fecha de nacimiento</Label>
										<Input
											dusk="birthDate"
											required
											type="date"
											value={data.patient_birth_date}
											autoComplete="bday"
											onChange={(e) =>
												setData("patient_birth_date", e.target.value)
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
											<ErrorMessage>{errors.patient_gender}</ErrorMessage>
										)}
									</Field>
								</div>
							</section>

							<section className="space-y-4 rounded-xl border border-zinc-200/70 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50 lg:col-span-2">
								<p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									<PhoneIcon className="size-4" />
									Contacto y notas
								</p>
								<Field>
									<Label className="inline-flex items-center gap-2">
										<PhoneIcon className="size-4 text-zinc-400" />
										Teléfono de contacto
									</Label>
									<div data-slot="control" className="flex flex-1 gap-2">
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
										<ErrorMessage>{errors.patient_phone}</ErrorMessage>
									)}
								</Field>
								<Field>
									<Label className="inline-flex items-center gap-2">
										<PencilSquareIcon className="size-4 text-zinc-400" />
										Notas para el cliente
									</Label>
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
								<div className="rounded-lg border border-sky-500/30 bg-sky-500/10 p-3">
									<CheckboxField>
										<Checkbox
											color="sky"
											checked={data.send_notification_email}
											onChange={(value) =>
												setData("send_notification_email", value)
											}
										/>
										<Label>
											Enviar correo de notificación al paciente
										</Label>
										<Description>
											Se enviará un correo con la confirmación o
											actualización de su cita.
										</Description>
									</CheckboxField>
								</div>
							</section>
						</div>
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
