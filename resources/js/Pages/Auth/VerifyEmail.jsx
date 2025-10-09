import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowPathIcon,
	ArrowRightStartOnRectangleIcon,
} from "@heroicons/react/16/solid";

export default function VerifyEmail() {
	const { post, processing } = useForm({});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("verification.send"));
		}
	};

	return (
		<AuthLayout
			title="Verificación de correo electrónico"
			header={
				<>
					<Heading>Verificación de correo electrónico</Heading>

					<Text>
						Gracias por registrarte. Antes de comenzar, por favor
						verifica tu dirección de correo electrónico haciendo
						clic en el enlace que te hemos enviado. Si no has
						recibido el correo electrónico, con gusto te lo
						reenviaremos.
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
				<Button className="w-full" disabled={processing} type="submit">
					Reenviar correo electrónico de verificación
					{processing && <ArrowPathIcon className="animate-spin" />}
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
