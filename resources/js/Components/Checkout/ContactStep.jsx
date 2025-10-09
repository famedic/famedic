export default function ContactStep({
	data,
	setData,
	errors,
	clearErrors,
	description = "Es indispensable que la orden se genere con la información de la persona que se realizará los estudios.",
	contacts,
	toggleContactForm,
	showContactForm,
	...props
}) {
	const selectedContact = useMemo(() => {
		return contacts.find((contact) => contact.id == data.contact);
	}, [data.contact]);

	const stepHeading = useMemo(() => {
		if (showContactForm) {
			return "Ingresa los datos del paciente";
		}
		return data.contact ? "Paciente" : "Selecciona el paciente";
	}, [showContactForm, data.contact]);

	return (
		<CheckoutStep
			{...props}
			IconComponent={IdentificationIcon}
			heading={stepHeading}
			description={description}
			defaultOpen={!selectedContact}
			selectedContent={
				<>
					<Text>{selectedContact?.full_name}</Text>
					<Text>{selectedContact?.formatted_gender}</Text>
					<Text>{selectedContact?.formatted_birth_date}</Text>
					<Text>{selectedContact?.phone}</Text>
				</>
			}
			formContent={
				<>
					{contacts.length > 0 && !showContactForm && (
						<ContactSelection
							setData={setData}
							contacts={contacts}
							toggleContactForm={toggleContactForm}
							clearErrors={clearErrors}
						/>
					)}
					{showContactForm && (
						<ContactForm
							setCheckoutData={setData}
							toggleContactForm={toggleContactForm}
							showContactsButton={contacts.length > 0}
						/>
					)}
				</>
			}
			onClickEdit={() => setData("contact", null)}
		/>
	);
}

function ContactSelection({
	setData,
	contacts,
	toggleContactForm,
	clearErrors,
}) {
	const close = useClose();

	const selectContact = (contact) => {
		setData("contact", contact.id);
		clearErrors("contact");
		clearErrors("contact_name");
		clearErrors("contact_paternal_lastname");
		clearErrors("contact_maternal_lastname");
		clearErrors("contact_phone");
		clearErrors("contact_phone_country");
		clearErrors("contact_birth_date");
		clearErrors("contact_gender");
		close();
	};

	return (
		<ul className="mt-3 grid gap-8 sm:grid-cols-2">
			{contacts.map((contact) => (
				<CheckoutSelectionCard
					key={contact.id}
					onClick={() => selectContact(contact)}
					heading={contact.full_name}
					IconComponent={UserCircleIcon}
				>
					<Badge color="slate" className="mb-2">
						{contact.formatted_gender}
					</Badge>
					<span className="flex items-center gap-1">
						<PhoneIcon className="size-5 fill-zinc-300 dark:fill-slate-600" />
						<Text>{contact.phone}</Text>
					</span>
					<span className="flex items-center gap-1">
						<CalendarDaysIcon className="size-5 fill-zinc-300 dark:fill-slate-600" />
						<Text>{contact.formatted_birth_date}</Text>
					</span>
				</CheckoutSelectionCard>
			))}
			<CheckoutSelectionCard
				onClick={toggleContactForm}
				heading="Nuevo paciente"
				IconComponent={PlusIcon}
				greenIcon
			>
				<Text className="line-clamp-3 max-w-64">
					Puedes agregar un nuevo paciente y guardarlo para futuras
					compras.
				</Text>
			</CheckoutSelectionCard>
		</ul>
	);
}

function ContactForm({
	toggleContactForm,
	showContactsButton,
	setCheckoutData,
}) {
	const close = useClose();

	const { genders } = usePage().props;

	const { data, setData, processing, errors, setError, clearErrors } =
		useForm({
			name: "",
			paternal_lastname: "",
			maternal_lastname: "",
			birth_date: "",
			gender: "",
			phone: "",
			phone_country: "MX",
		});

	const submit = () => {
		axios
			.post(route("checkout.contacts.store"), {
				...data,
			})
			.then((response) => {
				router.reload({
					only: ["contacts"],
					onFinish: () => {
						setCheckoutData("contact", response.data.contact);
						close();
					},
				});
			})
			.catch((error) => {
				if (error.status === 422) {
					clearErrors();
					setError(error.response.data.errors);
				}
			});
	};

	return (
		<>
			{showContactsButton && (
				<>
					<Button
						outline
						onClick={toggleContactForm}
						className="mb-4"
					>
						<ChevronLeftIcon className="size-4" />
						Mis pacientes
					</Button>
				</>
			)}
			<div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
				<Field>
					<Label>Nombre</Label>
					<Input
						dusk="name"
						required
						value={data.name}
						onChange={(e) => setData("name", e.target.value)}
						type="text"
						autoComplete="given-name"
					/>
					{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
				</Field>
				<Field>
					<Label>Apellido paterno</Label>
					<Input
						dusk="paternalLastname"
						required
						value={data.paternal_lastname}
						onChange={(e) =>
							setData("paternal_lastname", e.target.value)
						}
						type="text"
						autoComplete="family-name"
					/>
					{errors.paternal_lastname && (
						<ErrorMessage>{errors.paternal_lastname}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Apellido materno</Label>
					<Input
						dusk="maternalLastname"
						required
						value={data.maternal_lastname}
						onChange={(e) =>
							setData("maternal_lastname", e.target.value)
						}
						type="text"
						autoComplete="family-name"
					/>
					{errors.maternal_lastname && (
						<ErrorMessage>{errors.maternal_lastname}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Teléfono de contacto</Label>
					<div data-slot="control" className="flex flex-1 gap-2">
						<CountryListbox
							setCountry={(e) => setData("phone_country", e)}
							country={data.phone_country}
							className="max-w-32"
						/>
						<Input
							dusk="phone"
							required
							value={data.phone}
							onChange={(e) => setData("phone", e.target.value)}
							type="text"
							autoComplete="tel-national"
						/>
					</div>
					{errors.phone && (
						<ErrorMessage>{errors.phone}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Fecha de nacimiento</Label>
					<Input
						dusk="birthDate"
						required
						type="date"
						value={data.birth_date}
						autoComplete="bday"
						onChange={(e) => setData("birth_date", e.target.value)}
					/>
					{errors.birth_date && (
						<ErrorMessage>{errors.birth_date}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Sexo</Label>
					<Select
						dusk="gender"
						required
						value={data.gender}
						onChange={(e) => setData("gender", e.target.value)}
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
					{errors.gender && (
						<ErrorMessage>{errors.gender}</ErrorMessage>
					)}
				</Field>
			</div>
			<div className="mt-6 flex justify-end sm:col-span-2">
				<Button
					onClick={submit}
					type="button"
					className={`w-full sm:w-auto ${processing ? "opacity-0" : ""}`}
					disabled={processing}
				>
					Guardar paciente
				</Button>
			</div>
		</>
	);
}

import { useMemo } from "react";
import { router, usePage, useForm } from "@inertiajs/react";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";
import { Badge } from "@/Components/Catalyst/badge";
import { useClose } from "@headlessui/react";
import CountryListbox from "@/Components/CountryListbox";
import {
	PlusIcon,
	UserCircleIcon,
	ChevronLeftIcon,
	PhoneIcon,
	CalendarDaysIcon,
} from "@heroicons/react/16/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import { IdentificationIcon } from "@heroicons/react/24/solid";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import { Button } from "@/Components/Catalyst/button";
