import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import OdessaLinkingMessage from "@/Components/Auth/OdessaLinkingMessage";
import { useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { useState } from "react";

export default function OdessaUpgradeConfirm({
	secondsLeft,
	odessaToken,
	canUpgrade = true,
}) {
	const [expired, setExpired] = useState(false);
	const { post, processing } = useForm({});

	function handleSubmit(e) {
		e.preventDefault();
		post(route("odessa-upgrade.store", { odessa_token: odessaToken }));
	}

	return (
		<AuthLayout
			showOdessaLogo
			title="Confirmar actualización a Afiliado Odessa"
			header={
				<>
					<Heading>Vincula tu cuenta con Odessa</Heading>
					{canUpgrade && (
						<>
							<Text>
								Actualizaremos tu cuenta de Famedic a afiliado
								de Odessa. No te preocupes, conservaremos tu
								historial e información intacta.
							</Text>
							<OdessaLinkingMessage
								secondsLeft={secondsLeft}
								onTimerExpired={() => setExpired(true)}
							/>
						</>
					)}
				</>
			}
		>
			{!expired && (
				<>
					{canUpgrade ? (
						<form onSubmit={handleSubmit} className="space-y-2">
							<Button
								className="w-full"
								type="submit"
								disabled={processing}
							>
								Confirmar actualización
								{processing && (
									<ArrowPathIcon className="animate-spin" />
								)}
							</Button>
						</form>
					) : (
						<div className="space-y-3">
							<div className="rounded-md border border-orange-200 bg-orange-50 p-4 text-orange-800">
								No es posible registrar la cuenta actual como
								afiliado de Odessa. Si consideras que esto es un
								error, por favor contacta a soporte.
							</div>
							<Button
								href={route("home")}
								className="w-full"
								outline
							>
								Ir al inicio
							</Button>
						</div>
					)}
				</>
			)}
		</AuthLayout>
	);
}
