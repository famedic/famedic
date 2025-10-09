import AuthLayout from "@/Layouts/AuthLayout";
import { Badge } from "@/Components/Catalyst/badge";
import {
	ArrowPathIcon,
	ArrowRightStartOnRectangleIcon,
	DevicePhoneMobileIcon,
} from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";

export default function VerifyEmail({ auth }) {
	const { post, processing } = useForm({});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("phone.verification.send"));
		}
	};

	return (
		<AuthLayout
			title="Verificación de teléfono"
			header={
				<>
					<Heading>Verificación de teléfono</Heading>

					<Text>Es necesario verificar tu teléfono.</Text>
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
					<Text>
						Al dar clic en el botón de abajo, te enviaremos un
						código de verificación a tu teléfono.
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
				<Button className="w-full" disabled={processing} type="submit">
					Enviar código de verificación
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
				<Button
					dusk="logout"
					href={route("logout")}
					method="post"
					plain
					as="button"
					className="w-full"
					disabled={processing}
					type="button"
				>
					<ArrowRightStartOnRectangleIcon />
					Cerrar sesión
				</Button>
			</form>
		</AuthLayout>
	);
}
