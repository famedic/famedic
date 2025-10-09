export default function ContactInfoForm() {
	const { mustVerifyEmail, mustVerifyPhone, auth } = usePage().props;
	const user = auth.user;
	const [verifyPhoneAlertIsOpen, setVerifyPhoneAlertIsOpen] = useState(false);

	const { data, setData, put, errors, processing } = useForm({
		email: user.email,
		phone: user.phone ?? "",
		phone_country: user.phone_country ?? "MX",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			put(route("contact-info.update"), { preserveScroll: true });
		}
	};

	return (
		<form onSubmit={submit} className="grid gap-x-8 gap-y-6 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Información de contacto</Subheading>
				<Text>
					Utilizaremos su teléfono y correo para comunicarnos y
					gestionar sus pedidos, compartiéndolos solo con los
					proveedores necesarios.
				</Text>
			</div>

			<div className="space-y-6">
				<Field>
					<Label>Correo electrónico</Label>
					{mustVerifyEmail && (
						<Description className="flex items-start gap-1">
							<ShieldExclamationIcon className="mt-1 inline-block h-4 w-4 fill-red-500" />
							Tu correo electrónico debe verificarse.
						</Description>
					)}
					<InputGroup>
						<CheckBadgeIcon
							className={
								mustVerifyEmail
									? "stroke-red-600"
									: "stroke-famedic-light"
							}
						/>
						<div className="flex flex-col gap-2 lg:flex-row lg:items-center">
							<Input
								dusk="email"
								required
								type="email"
								value={data.email}
								autoComplete="email"
								onChange={(e) =>
									setData("email", e.target.value)
								}
							/>
							{mustVerifyEmail && (
								<Button
									dusk="emailVerify"
									preserveScroll
									as="button"
									type="button"
									href={route("verification.send")}
									method="post"
									outline
									className="w-full lg:w-auto"
								>
									<EnvelopeIcon />
									Verificar
								</Button>
							)}
						</div>
					</InputGroup>
					{errors.email && (
						<ErrorMessage>{errors.email}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Teléfono celular</Label>
					{mustVerifyPhone && (
						<Description className="flex items-start gap-1">
							<ShieldExclamationIcon className="mt-1 inline-block h-4 w-4 fill-red-500" />
							Tu teléfono celular debe verificarse.
						</Description>
					)}
					<InputGroup>
						<CheckBadgeIcon
							className={
								mustVerifyPhone
									? "!left-[9.3rem] stroke-red-600 sm:!left-[9.1rem]"
									: "!left-[9.3rem] stroke-famedic-light sm:!left-[9.1rem]"
							}
						/>

						<div className="flex flex-col gap-2 xl:flex-row">
							<div className="flex flex-1 gap-2">
								<CountryListbox
									setCountry={(e) =>
										setData("phone_country", e)
									}
									country={data.phone_country}
									className="max-w-32"
								/>
								<Input
									dusk="phone"
									required
									type="phone"
									value={data.phone}
									autoComplete="tel-national"
									onChange={(e) =>
										setData("phone", e.target.value)
									}
								/>
							</div>
							{mustVerifyPhone && (
								<Button
									dusk="phoneVerify"
									type="button"
									outline
									onClick={() =>
										setVerifyPhoneAlertIsOpen(true)
									}
									className="w-full lg:w-auto"
								>
									<DevicePhoneMobileIcon />
									Verificar
								</Button>
							)}
						</div>
					</InputGroup>
					{errors.phone && (
						<ErrorMessage>{errors.phone}</ErrorMessage>
					)}
				</Field>
			</div>

			<div className="flex justify-end sm:col-span-2">
				<Button
					dusk="updateContactInfo"
					disabled={processing}
					type="submit"
					className="w-full sm:w-auto"
				>
					Actualizar información de contacto
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</div>

			{mustVerifyPhone && (
				<Alert
					open={verifyPhoneAlertIsOpen}
					onClose={setVerifyPhoneAlertIsOpen}
				>
					<AlertTitle>Verificación de teléfono</AlertTitle>
					<AlertDescription>
						Te enviaremos un código de verificación a tu teléfono.
					</AlertDescription>
					<AlertActions>
						<Button
							plain
							onClick={() => setVerifyPhoneAlertIsOpen(false)}
						>
							Cancelar
						</Button>
						<Button
							as="button"
							href={route("phone.verification.send")}
							method="post"
						>
							Enviar
						</Button>
					</AlertActions>
				</Alert>
			)}
		</form>
	);
}

import {
	Alert,
	AlertActions,
	AlertDescription,
	AlertTitle,
} from "@/Components/Catalyst/alert";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import {
	ErrorMessage,
	Field,
	Label,
	Description,
} from "@/Components/Catalyst/fieldset";
import { useForm, usePage } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { CheckBadgeIcon } from "@heroicons/react/24/outline";
import { ShieldExclamationIcon } from "@heroicons/react/24/solid";
import {
	EnvelopeIcon,
	DevicePhoneMobileIcon,
	CheckIcon,
	ArrowPathIcon,
} from "@heroicons/react/16/solid";
import CountryListbox from "@/Components/CountryListbox";
import { useState } from "react";
