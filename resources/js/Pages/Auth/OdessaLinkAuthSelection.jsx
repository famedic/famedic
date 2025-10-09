import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import OdessaLinkingMessage from "@/Components/Auth/OdessaLinkingMessage";
import { useState } from "react";

export default function OdessaLinkAuthSelection({ secondsLeft }) {
	const [expired, setExpired] = useState(false);

	return (
		<AuthLayout
			showOdessaLogo
			title="Vinculación de cuenta Odessa"
			header={
				<>
					<Heading>
						Vincula tu cuenta y disfruta de beneficios exclusivos
					</Heading>

					<Text>
						Para acceder a los beneficios exclusivos de Famedic como
						miembro de Odessa, debes vincular tu cuenta de Odessa
						con Famedic.
					</Text>
					<OdessaLinkingMessage
						secondsLeft={secondsLeft}
						onTimerExpired={() => setExpired(true)}
					/>
				</>
			}
		>
			{!expired && (
				<>
					<Button
						href={route("odessa-register.index", {
							odessa_token: route().params.odessa_token,
						})}
						className="mb-4 w-full"
					>
						Crear nueva cuenta
					</Button>
					<Button
						outline
						className="w-full"
						href={route("odessa-upgrade.index", {
							odessa_token: route().params.odessa_token,
						})}
					>
						Ya tengo cuenta de Famedic
					</Button>
				</>
			)}
		</AuthLayout>
	);
}
