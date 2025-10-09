import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { LockOpenIcon } from "@heroicons/react/16/solid";

export default function OdessaLinkAuthSelection({ loginUrl }) {
	return (
		<AuthLayout
			showOdessaLogo
			title="¡Bienvenido!"
			header={
				<>
					<Heading>¡Bienvenido a Famedic!</Heading>

					<Text>
						Hemos
						<span class="font-medium text-famedic-light">
							{" "}
							identificado tu cuenta automáticamente
						</span>{" "}
						a través de tu
						<span class="font-medium text-famedic-light">
							{" "}
							caja de ahorro.
						</span>
					</Text>
					<Text>
						Para acceder de forma segura, utiliza el siguiente
						enlace
					</Text>
				</>
			}
		>
			<a className="w-full" href={loginUrl} target="_blank">
				<Button className="mb-4 w-full">
					<LockOpenIcon />
					Entrar a Famedic
				</Button>
			</a>
		</AuthLayout>
	);
}
