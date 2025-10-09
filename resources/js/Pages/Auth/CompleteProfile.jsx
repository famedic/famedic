import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";
import CountryListbox from "@/Components/CountryListbox";
import { Input } from "@/Components/Catalyst/input";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function CompleteProfile({ auth, genders }) {
	const user = auth.user;

	const { data, setData, post, processing, errors } = useForm({
		name: user.name ?? "",
		paternal_lastname: user.paternal_lastname ?? "",
		maternal_lastname: user.maternal_lastname ?? "",
		birth_date: user.birth_date_string ?? "",
		email: user.email ?? "",
		phone: user.phone ?? "",
		phone_country: user.phone_country ?? "MX",
		gender: user.gender ?? "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("complete-profile.store"));
		}
	};

	return (
		<AuthLayout
			title="Confirmar contraseña"
			header={
				<>
					<Heading>Completa tu perfil</Heading>

					<Text>
						Antes de continuar, es necesario que completes tu
						perfil.
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
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
					<Label>Correo electrónico</Label>
					<Input
						dusk="email"
						required
						type="email"
						value={data.email}
						autoComplete="email"
						onChange={(e) => setData("email", e.target.value)}
					/>
					{errors.email && (
						<ErrorMessage>{errors.email}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Teléfono celular</Label>
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

				<Button
					dusk="completeProfile"
					className="w-full"
					disabled={processing}
					type="submit"
				>
					Completar perfil
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</form>
		</AuthLayout>
	);
}
