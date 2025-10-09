import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { useForm } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ArrowPathIcon, EnvelopeIcon } from "@heroicons/react/16/solid";

export default function ForgotPassword() {
	const { data, setData, post, processing, errors } = useForm({
		email: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("password.email"));
		}
	};

	return (
		<AuthLayout
			title="¿Olvidaste tu contraseña?"
			header={
				<>
					<Heading>¿Olvidaste tu contraseña?</Heading>

					<Text>
						No hay problema, solo tienes que indicarnos tu correo
						electrónico y te enviaremos un enlace para restablecer
						tu contraseña.
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
				<Field>
					<Label>Correo electrónico</Label>
					<Input
						dusk="email"
						required
						type="email"
						value={data.email}
						autoComplete="username"
						onChange={(e) => setData("email", e.target.value)}
					/>
					{errors.email && (
						<ErrorMessage>{errors.email}</ErrorMessage>
					)}
				</Field>

				<Button
					dusk="requestPasswordResetLink"
					className="w-full"
					disabled={processing}
					type="submit"
				>
					<EnvelopeIcon />
					Enviar enlace
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</form>
		</AuthLayout>
	);
}
