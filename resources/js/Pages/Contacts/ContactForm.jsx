export default function ContactForm({ isOpen }) {
	const { contact, genders } = usePage().props;

	const [cachedGenders, setCachedGenders] = useState(genders);
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("contacts.edit"),
	);
	const [cachedContact, setCachedContact] = useState(contact);

	const resetFormData = (contact) => ({
		name: contact?.name ?? "",
		paternal_lastname: contact?.paternal_lastname ?? "",
		maternal_lastname: contact?.maternal_lastname ?? "",
		birth_date: contact?.birth_date_string ?? "",
		gender: contact?.gender ?? "",
		phone: contact?.phone ?? "",
		phone_country: contact?.phone_country ?? "MX",
	});

	const { data, setData, post, put, processing, errors } = useForm(
		resetFormData(contact),
	);

	useEffect(() => {
		if (isOpen) {
			setCachedGenders(genders);
			setCachedContact(contact);
			setCachedEditMode(route().current("contacts.edit") ?? false);
			setData(resetFormData(contact));
		}
	}, [isOpen]);

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (cachedEditMode) {
				put(route("contacts.update", { contact: cachedContact }), {
					preserveScroll: true,
				});
			} else {
				post(route("contacts.store"), { preserveScroll: true });
			}
		}
	};

	const closeDialog = () => {
		router.get(
			route("contacts.index"),
			{},
			{ preserveState: true, preserveScroll: true },
		);
	};

	return (
		<Dialog open={isOpen} onClose={closeDialog}>
			<form dusk="contactForm" onSubmit={submit}>
				<DialogTitle>
					{cachedEditMode
						? `Edita tu paciente ${data?.name ? `"${data.name}"` : ""}`
						: "Agregar paciente frecuente"}
				</DialogTitle>
				<DialogDescription>
					Ingresa la información de tu paciente.
				</DialogDescription>
				<DialogBody className="space-y-6">
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
						{errors.name && (
							<ErrorMessage>{errors.name}</ErrorMessage>
						)}
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
							<ErrorMessage>
								{errors.paternal_lastname}
							</ErrorMessage>
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
							<ErrorMessage>
								{errors.maternal_lastname}
							</ErrorMessage>
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
								onChange={(e) =>
									setData("phone", e.target.value)
								}
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
							onChange={(e) =>
								setData("birth_date", e.target.value)
							}
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
							{cachedGenders.map(({ label, value }) => (
								<option key={value} value={value}>
									{label}
								</option>
							))}
						</Select>
						{errors.gender && (
							<ErrorMessage>{errors.gender}</ErrorMessage>
						)}
					</Field>
				</DialogBody>
				<DialogActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={closeDialog}
					>
						Cancelar
					</Button>
					<Button
						dusk="saveContact"
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
	);
}

import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { usePage, useForm, router } from "@inertiajs/react";
import { Select } from "@/Components/Catalyst/select";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import CountryListbox from "@/Components/CountryListbox";
import { useEffect, useState } from "react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
