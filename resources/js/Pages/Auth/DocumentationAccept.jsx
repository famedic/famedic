import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Anchor, Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import {
	ArrowPathIcon,
	ArrowTopRightOnSquareIcon,
} from "@heroicons/react/16/solid";

export default function ConfirmPassword() {
	const { post, processing } = useForm({});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("documentation.accept.store"));
		}
	};

	return (
		<AuthLayout
			title="Aceptación de documentación legal"
			header={
				<>
					<Heading>Nuestra documentación legal ha cambiado</Heading>
					<Text>
						Antes de continuar, es necesario que leas y aceptes
						nuestros términos y condiciones y política de
						privacidad.
					</Text>
					<ul className="space-y-4">
						<li>
							<Anchor
								target="_blank"
								href={route("terms-of-service")}
							>
								<ArrowTopRightOnSquareIcon className="inline-block size-5 align-middle" />
								Términos y condiciones
							</Anchor>
						</li>
						<li>
							<Anchor
								target="_blank"
								href={route("privacy-policy")}
							>
								<ArrowTopRightOnSquareIcon className="inline-block size-5 align-middle" />
								Política de privacidad
							</Anchor>
						</li>
					</ul>
				</>
			}
		>
			<form onSubmit={submit}>
				<Button
					dusk="confirmPassword"
					className="w-full"
					disabled={processing}
					type="submit"
				>
					Aceptar y continuar
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</form>
		</AuthLayout>
	);
}
