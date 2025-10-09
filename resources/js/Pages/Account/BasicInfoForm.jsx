import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";
import { useForm, usePage } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function BasicInfoForm() {
	const { auth, genders } = usePage().props;

	const user = auth.user;

	const { data, setData, put, errors, processing } = useForm({
		name: user.name,
		paternal_lastname: user.paternal_lastname,
		maternal_lastname: user.maternal_lastname,
		birth_date: user.birth_date_string ?? "",
		gender: user.gender ?? "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			put(route("basic-info.update"), {
				preserveScroll: true,
			});
		}
	};

	return (
		<form onSubmit={submit} className="grid gap-x-8 gap-y-6 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Información básica</Subheading>
				<Text>
					La información proporcionada será utilizada únicamente para
					cumplir con sus pedidos y será compartida solo con los
					proveedores necesarios para este fin.
				</Text>
			</div>

			<div className="space-y-6">
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

			<div className="flex justify-end sm:col-span-2">
				<Button
					dusk="updateBasicInfo"
					disabled={processing}
					type="submit"
					className="w-full sm:w-auto"
				>
					Actualizar información básica
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</div>
		</form>
	);
}
