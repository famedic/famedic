import AuthLayout from "@/Layouts/AuthLayout";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";
import { LockClosedIcon } from "@heroicons/react/20/solid";
import { ArrowPathIcon, EyeIcon, EyeSlashIcon } from "@heroicons/react/16/solid";
import ForgotPasswordLink from "@/Components/ForgotPasswordLink";
import { useState } from "react"; // Importar useState

export default function ConfirmPassword() {
	const { data, setData, post, processing, errors, reset } = useForm({
		password: "",
	});

	const [showPassword, setShowPassword] = useState(false); // Estado para mostrar/ocultar contraseña

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
					<div className="relative">
						<Input
							dusk="password"
							required
							type={showPassword ? "text" : "password"} // Alternar entre text y password
							value={data.password}
							autoComplete="current-password"
							onChange={(e) => setData("password", e.target.value)}
							className="pr-10" // Espacio para el botón
						/>
						<button
							type="button"
							className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
							onClick={() => setShowPassword(!showPassword)}
							aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
						>
							{showPassword ? (
								<EyeSlashIcon className="h-5 w-5" />
							) : (
								<EyeIcon className="h-5 w-5" />
							)}
						</button>
					</div>
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