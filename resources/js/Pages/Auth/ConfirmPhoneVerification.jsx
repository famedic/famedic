import AuthLayout from "@/Layouts/AuthLayout";
import { Badge } from "@/Components/Catalyst/badge";
import {
	ArrowPathIcon,
	DevicePhoneMobileIcon,
} from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { ArrowRightStartOnRectangleIcon } from "@heroicons/react/16/solid";
import { Input } from "@/Components/Catalyst/input";
import { Label, ErrorMessage, Field } from "@/Components/Catalyst/fieldset";

export default function ConfirmPhoneVerification({ auth }) {
	const { data, setData, post, errors, processing } = useForm({
		code: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("phone.verification.confirm"));
		}
	};

	return (
		<AuthLayout
			title="Ingrese el código"
			header={
				<>
					<Heading>Ingrese el código</Heading>

					<Text>Hemos enviado un código a tu teléfono.</Text>
					<Badge color="sky">
						<DevicePhoneMobileIcon className="size-4" />
						{auth.user.phone}.
					</Badge>

					<Text>
						Si tu número ha cambiado, puedes
						<TextLink href={route("user.edit")}>
							{" "}
							actualizarlo aquí.
						</TextLink>
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
				<Field>
					<Input
						dusk="code"
						required
						value={data.code}
						onChange={(e) => setData("code", e.target.value)}
						type="text"
						autoComplete="one-time-code"
					/>
					{errors.code && <ErrorMessage>{errors.code}</ErrorMessage>}
				</Field>
				<Button className="w-full" disabled={processing} type="submit">
					Continuar
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
				<Button
					outline
					as="button"
					className="w-full"
					method="post"
					href={route("phone.verification.send")}
				>
					Reenviar código
				</Button>
				<Button
					dusk="logout"
					href={route("logout")}
					method="post"
					plain
					as="button"
					className="w-full"
					type="button"
				>
					<ArrowRightStartOnRectangleIcon />
					Cerrar sesión
				</Button>
			</form>
		</AuthLayout>
	);
}
