import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { router, useForm } from "@inertiajs/react";
import { Input } from "@/Components/Catalyst/input";
import CountryListbox from "@/Components/CountryListbox";
import { Select } from "@/Components/Catalyst/select";
import { Heading } from "@/Components/Catalyst/heading";
import { Anchor, Text, TextLink } from "@/Components/Catalyst/text";
import OdessaLinkingMessage from "@/Components/Auth/OdessaLinkingMessage";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function Register({
	genders,
	inviter = null,
	odessaToken = null,
	secondsLeft = 0,
}) {
	const { data, setData, post, processing, errors, reset } = useForm({
		name: "",
		paternal_lastname: "",
		maternal_lastname: "",
		birth_date: "",
		gender: "",
		email: "",
		phone: "",
		phone_country: "MX",
		password: "",
		password_confirmation: "",
		referrer_id: inviter?.id || null,
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (odessaToken) {
				post(
					route("odessa-register.store", {
						odessa_token: odessaToken,
					}),
					{
						onFinish: () =>
							reset("password", "password_confirmation"),
					},
				);
			} else {
				post(route("register"), {
					onFinish: () => reset("password", "password_confirmation"),
				});
			}
		}
	};

	return (
		<AuthLayout
			showOdessaLogo={!!odessaToken}
			title="Regístrate"
			header={
				<>
					<Heading>Crea tu nueva cuenta</Heading>

					<Text>
						¿Ya tienes una cuenta?{" "}
						<TextLink href={route("login")}>Inicia sesión</TextLink>
					</Text>

					{odessaToken && (
						<OdessaLinkingMessage
							secondsLeft={secondsLeft}
							onTimerExpired={() => router.get(route("/"))}
						/>
					)}
				</>
			}
		>
			{inviter && (
				<div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-slate-700 dark:bg-famedic-darker">
					<Text className="text-center">
						🎉{" "}
						{inviter.name && inviter.name !== "Usuario" ? (
							<>
								<strong>{inviter.name}</strong> te ha invitado a
								unirte y disfrutar los beneficios de Famedic!
							</>
						) : (
							<>
								Te han invitado a unirte y disfrutar los
								beneficios de Famedic!
							</>
						)}
					</Text>
				</div>
			)}

			<form className="space-y-6" onSubmit={submit}>
				<Field>
					<Label>Nombre</Label>
					<Input
						dusk="name"
						required
						type="text"
						value={data.name}
						autoComplete="given-name"
						onChange={(e) => setData("name", e.target.value)}
					/>
					{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
				</Field>

				<Field>
					<Label>Apellido paterno</Label>
					<Input
						dusk="paternalLastname"
						required
						type="text"
						value={data.paternal_lastname}
						autoComplete="family-name"
						onChange={(e) =>
							setData("paternal_lastname", e.target.value)
						}
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
						type="text"
						value={data.maternal_lastname}
						autoComplete="family-name"
						onChange={(e) =>
							setData("maternal_lastname", e.target.value)
						}
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
							type="tel"
							value={data.phone}
							onChange={(e) => setData("phone", e.target.value)}
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

				<Field>
					<Label>Contraseña</Label>
					<Input
						dusk="password"
						required
						type="password"
						value={data.password}
						autoComplete="new-password"
						onChange={(e) => setData("password", e.target.value)}
					/>
					{errors.password && (
						<ErrorMessage>{errors.password}</ErrorMessage>
					)}
				</Field>

				<Field>
					<Label>Confirma tu Contraseña</Label>
					<Input
						dusk="passwordConfirmation"
						required
						type="password"
						value={data.password_confirmation}
						autoComplete="new-password"
						onChange={(e) =>
							setData("password_confirmation", e.target.value)
						}
					/>
					{errors.password_confirmation && (
						<ErrorMessage>
							{errors.password_confirmation}
						</ErrorMessage>
					)}
				</Field>

				<div className="space-y-2">
					<Button
						dusk="register"
						className="w-full"
						disabled={processing}
						type="submit"
					>
						Registrar
						{processing && (
							<ArrowPathIcon className="animate-spin" />
						)}
					</Button>

					<Text className="mb-8">
						Al hacer clic en el botón "Registrar", aceptas todos los{" "}
						<Anchor
							href={route("terms-of-service")}
							target="_blank"
						>
							Términos y condiciones de servicio
						</Anchor>{" "}
						y la{" "}
						<Anchor href={route("privacy-policy")} target="_blank">
							Política de privacidad
						</Anchor>
						.
					</Text>
				</div>
			</form>
		</AuthLayout>
	);
}
