import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { LockClosedIcon } from "@heroicons/react/20/solid";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import ForgotPasswordLink from "@/Components/ForgotPasswordLink";

export default function ConfirmPassword() {
	const { data, setData, post, processing, errors, reset } = useForm({
		password: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("password.confirm"), {
				onFinish: () => reset("password"),
			});
		}
	};

	return (
		<AuthLayout
			title="Area protegida"
			header={
				<>
					<Heading className="flex items-center gap-2">
						<LockClosedIcon className="size-6" />
						<p>Area protegida</p>
					</Heading>

					<Text>
						Esta es un área segura de la aplicación. Por favor,
						confirma tu contraseña antes de continuar.
					</Text>
				</>
			}
		>
			<form className="space-y-6" onSubmit={submit}>
				<Field>
					<Label>Contraseña</Label>
					<Input
						dusk="password"
						required
						type="password"
						value={data.password}
						autoComplete="current-password"
						onChange={(e) => setData("password", e.target.value)}
					/>
					{errors.password && (
						<ErrorMessage>{errors.password}</ErrorMessage>
					)}
				</Field>

				<ForgotPasswordLink />

				<Button
					dusk="confirmPassword"
					className="w-full"
					disabled={processing}
					type="submit"
				>
					Confirmar contraseña
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</form>
		</AuthLayout>
	);
}
