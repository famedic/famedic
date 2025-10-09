export default function FamilyAccountForm({ isOpen }) {
	const { familyAccount, kinships, genders, allowedKinships } = usePage().props;

	const [cachedGenders, setCachedGenders] = useState(genders);
	const [cachedKinships, setCachedKinships] = useState(kinships);
	const [cachedAllowedKinships, setCachedAllowedKinships] = useState(allowedKinships);
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("family.edit"),
	);
	const [cachedFamilyAccount, setCachedFamilyAccount] =
		useState(familyAccount);

	const resetFormData = (familyAccount) => ({
		name: familyAccount?.name ?? "",
		paternal_lastname: familyAccount?.paternal_lastname ?? "",
		maternal_lastname: familyAccount?.maternal_lastname ?? "",
		birth_date: familyAccount?.birth_date_string ?? "",
		gender: familyAccount?.gender ?? "",
		kinship: familyAccount?.kinship ?? "",
	});

	const { data, setData, post, put, processing, errors } = useForm(
		resetFormData(familyAccount),
	);

	useEffect(() => {
		if (isOpen) {
			setCachedGenders(genders);
			setCachedKinships(kinships);
			setCachedAllowedKinships(allowedKinships);
			setCachedFamilyAccount(familyAccount);
			setCachedEditMode(route().current("family.edit") ?? false);
			setData(resetFormData(familyAccount));
		}
	}, [isOpen]);

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (cachedEditMode) {
				put(
					route("family.update", {
						family_account: cachedFamilyAccount,
					}),
					{
						preserveScroll: true,
					},
				);
			} else {
				post(route("family.store"), { preserveScroll: true });
			}
		}
	};

	const closeDialog = () => {
		router.get(
			route("family.index"),
			{},
			{ preserveState: true, preserveScroll: true },
		);
	};

	return (
		<Dialog open={isOpen} onClose={closeDialog}>
			<form onSubmit={submit}>
				<DialogTitle>
					{cachedEditMode
						? `Edita tu familiar ${data?.name ? `"${data.name}"` : ""}`
						: "Agregar familiar"}
				</DialogTitle>
				<DialogDescription>
					Ingresa la información de tu familiar.
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
						<Label>Parentesco</Label>
						{cachedAllowedKinships?.length < cachedKinships?.length && (
							<p className="text-sm text-gray-600 mb-2">
								Puedes agregar cónyuge e hijos, o padres, pero no mezclar ambos grupos
							</p>
						)}
						<Select
							required
							value={data.kinship}
							onChange={(e) => setData("kinship", e.target.value)}
						>
							<option value="" disabled>
								Selecciona una opción
							</option>
							{cachedKinships.map(({ label, value }) => (
								<option 
									key={value} 
									value={value}
									disabled={!cachedAllowedKinships?.includes(value)}
									className={!cachedAllowedKinships?.includes(value) ? 'text-gray-400' : ''}
								>
									{label}
								</option>
							))}
						</Select>
						{errors.kinship && (
							<ErrorMessage>{errors.kinship}</ErrorMessage>
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
import { useEffect, useState } from "react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
