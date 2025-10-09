import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import ForgotPasswordLink from "@/Components/ForgotPasswordLink";

export default function UpdatePasswordForm() {
	const { data, setData, errors, put, reset, processing } = useForm({
		current_password: "",
		password: "",
		password_confirmation: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			put(route("password.update"), {
				preserveScroll: true,
				onSuccess: () => reset(),
				onError: (errors) => {
					if (errors.password) {
						reset("password", "password_confirmation");
					}

					if (errors.current_password) {
						reset("current_password");
					}
				},
			});
		}
	};

	return (
		<form onSubmit={submit} className="grid gap-x-8 gap-y-6 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Actualizar contraseña</Subheading>
				<Text>
					Utiliza una contraseña larga y aleatoria para mantener tu
					cuenta segura.
				</Text>
			</div>

			<div className="space-y-6">
				<Field>
					<Label>Contraseña actual</Label>
					<Input
						dusk="currentPassword"
						required
						type="password"
						value={data.current_password}
						autoComplete="current-password"
						onChange={(e) =>
							setData("current_password", e.target.value)
						}
					/>
					{errors.current_password && (
						<ErrorMessage>{errors.current_password}</ErrorMessage>
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

				<ForgotPasswordLink />
			</div>

			<div className="flex justify-end sm:col-span-2">
				<Button
					dusk="updatePassword"
					disabled={processing}
					type="submit"
					className="w-full sm:w-auto"
				>
					Actualizar contraseña
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</div>
		</form>
	);
}
