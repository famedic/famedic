import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useForm } from "@inertiajs/react";
import { Input } from "@/Components/Catalyst/input";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function ResetPassword({ token, email }) {
	const { data, setData, post, processing, errors, reset } = useForm({
		token: token,
		email: email,
		password: "",
		password_confirmation: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route("password.store"), {
				onFinish: () => reset("password", "password_confirmation"),
			});
		}
	};

	return (
		<AuthLayout
			title="Restablecer contraseña"
			header={
				<>
					<Heading>Restablecer contraseña</Heading>

					<Text>
						Para restablecer tu contraseña, por favor ingresa tu
						correo electrónico y la nueva contraseña.
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

				<Field>
					<Label>Nueva contraseña</Label>
					<Input
						dusk="password"
						required
						type="password"
						value={data.password}
						autoComplete="new-password"
						onChange={(e) => setData("password", e.target.value)}
					/>
					{errors.password && (
						<ErrorMessage>{errors.password}</ErrorMessage>
					)}
				</Field>

				<Field>
					<Label>Confirma la nueva contraseña</Label>
					<Input
						dusk="passwordConfirmation"
						required
						type="password"
						value={data.password_confirmation}
						autoComplete="new-password"
						onChange={(e) =>
							setData("password_confirmation", e.target.value)
						}
					/>
					{errors.password_confirmation && (
						<ErrorMessage>
							{errors.password_confirmation}
						</ErrorMessage>
					)}
				</Field>

				<Button
					dusk="resetPassword"
					className="w-full"
					disabled={processing}
					type="submit"
				>
					Restablecer contraseña
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</form>
		</AuthLayout>
	);
}
