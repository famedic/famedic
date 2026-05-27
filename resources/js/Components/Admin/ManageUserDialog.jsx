import { useEffect, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
	CheckCircleIcon,
	EnvelopeIcon,
	PhoneIcon,
	XCircleIcon,
	ArrowPathIcon,
} from "@heroicons/react/16/solid";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import CountryListbox from "@/Components/CountryListbox";
import StateSelect from "@/Components/StateSelect";

function buildFormData(user) {
	return {
		name: user.name ?? "",
		paternal_lastname: user.paternal_lastname ?? "",
		maternal_lastname: user.maternal_lastname ?? "",
		birth_date: user.birth_date_string ?? "",
		gender: user.gender != null ? String(user.gender) : "",
		state: user.state ?? "",
		email: user.email ?? "",
		phone: user.phone ?? "",
		phone_country: user.phone_country ?? "MX",
	};
}

export default function ManageUserDialog({ open, onClose, user, genders = [], states = {} }) {
	const { data, setData, patch, errors, processing, reset, clearErrors } =
		useForm(buildFormData(user));
	const [verifyingEmail, setVerifyingEmail] = useState(false);
	const [verifyingPhone, setVerifyingPhone] = useState(false);

	useEffect(() => {
		if (open) {
			reset(buildFormData(user));
			clearErrors();
		}
	}, [open, user]);

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			patch(route("admin.users.update", user.id), {
				preserveScroll: true,
				onSuccess: () => onClose(false),
			});
		}
	};

	const verifyEmail = () => {
		if (user.email_verified_at || verifyingEmail) {
			return;
		}

		setVerifyingEmail(true);
		router.post(route("admin.users.verify-email", user.id), {}, {
			preserveScroll: true,
			onFinish: () => setVerifyingEmail(false),
		});
	};

	const verifyPhone = () => {
		if (user.phone_verified_at || verifyingPhone) {
			return;
		}

		setVerifyingPhone(true);
		router.post(route("admin.users.verify-phone", user.id), {}, {
			preserveScroll: true,
			onFinish: () => setVerifyingPhone(false),
		});
	};

	return (
		<Dialog open={open} onClose={onClose} size="3xl">
			<form onSubmit={submit}>
				<DialogTitle>Gestionar usuario</DialogTitle>
				<DialogDescription>
					Actualiza la información del usuario y marca correo o teléfono como
					verificados cuando sea necesario.
				</DialogDescription>

				<DialogBody className="max-h-[70vh] space-y-8 overflow-y-auto">
					<section className="space-y-4">
						<Text className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
							Información básica
						</Text>
						<div className="grid gap-4 sm:grid-cols-2">
							<Field>
								<Label>Nombre</Label>
								<Input
									required
									value={data.name}
									onChange={(e) => setData("name", e.target.value)}
								/>
								{errors.name && (
									<ErrorMessage>{errors.name}</ErrorMessage>
								)}
							</Field>
							<Field>
								<Label>Apellido paterno</Label>
								<Input
									required
									value={data.paternal_lastname}
									onChange={(e) =>
										setData("paternal_lastname", e.target.value)
									}
								/>
								{errors.paternal_lastname && (
									<ErrorMessage>{errors.paternal_lastname}</ErrorMessage>
								)}
							</Field>
							<Field>
								<Label>Apellido materno</Label>
								<Input
									required
									value={data.maternal_lastname}
									onChange={(e) =>
										setData("maternal_lastname", e.target.value)
									}
								/>
								{errors.maternal_lastname && (
									<ErrorMessage>{errors.maternal_lastname}</ErrorMessage>
								)}
							</Field>
							<Field>
								<Label>Fecha de nacimiento</Label>
								<Input
									required
									type="date"
									value={data.birth_date}
									onChange={(e) => setData("birth_date", e.target.value)}
								/>
								{errors.birth_date && (
									<ErrorMessage>{errors.birth_date}</ErrorMessage>
								)}
							</Field>
							<Field>
								<Label>Sexo</Label>
								<Select
									required
									value={data.gender}
									onChange={(e) => setData("gender", e.target.value)}
								>
									<option value="" disabled>
										Selecciona una opción
									</option>
									{genders.map(({ label, value }) => (
										<option key={value} value={value}>
											{label}
										</option>
									))}
								</Select>
								{errors.gender && (
									<ErrorMessage>{errors.gender}</ErrorMessage>
								)}
							</Field>
							<StateSelect
								value={data.state}
								onChange={(value) => setData("state", value)}
								error={errors.state}
								backendStates={states}
								required={false}
								label="Estado de residencia"
							/>
						</div>
					</section>

					<section className="space-y-4">
						<Text className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
							Contacto
						</Text>
						<Field>
							<Label>Correo electrónico</Label>
							<Input
								required
								type="email"
								value={data.email}
								autoComplete="email"
								onChange={(e) => setData("email", e.target.value)}
							/>
							{errors.email && (
								<ErrorMessage>{errors.email}</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Teléfono celular</Label>
							<div className="flex gap-2">
								<CountryListbox
									setCountry={(value) => setData("phone_country", value)}
									country={data.phone_country}
									className="max-w-32 shrink-0"
								/>
								<Input
									required
									type="tel"
									className="flex-1"
									value={data.phone}
									autoComplete="tel-national"
									onChange={(e) => setData("phone", e.target.value)}
								/>
							</div>
							{errors.phone && (
								<ErrorMessage>{errors.phone}</ErrorMessage>
							)}
							{errors.phone_country && (
								<ErrorMessage>{errors.phone_country}</ErrorMessage>
							)}
						</Field>
					</section>

					<section className="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
						<Text className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
							Verificación
						</Text>
						<div className="flex flex-wrap gap-2">
							<Badge
								color={user.email_verified_at ? "famedic-lime" : "slate"}
							>
								{user.email_verified_at ? (
									<CheckCircleIcon className="size-4" />
								) : (
									<XCircleIcon className="size-4" />
								)}
								{user.email_verified_at
									? "Correo verificado"
									: "Correo no verificado"}
							</Badge>
							<Badge
								color={user.phone_verified_at ? "famedic-lime" : "slate"}
							>
								{user.phone_verified_at ? (
									<CheckCircleIcon className="size-4" />
								) : (
									<XCircleIcon className="size-4" />
								)}
								{user.phone_verified_at
									? "Teléfono verificado"
									: "Teléfono no verificado"}
							</Badge>
						</div>
						<div className="flex flex-wrap gap-2">
							<Button
								type="button"
								outline
								disabled={!!user.email_verified_at || verifyingEmail}
								onClick={verifyEmail}
							>
								<EnvelopeIcon />
								Marcar correo como verificado
								{verifyingEmail && (
									<ArrowPathIcon className="animate-spin" />
								)}
							</Button>
							<Button
								type="button"
								outline
								disabled={!!user.phone_verified_at || verifyingPhone}
								onClick={verifyPhone}
							>
								<PhoneIcon />
								Marcar teléfono como verificado
								{verifyingPhone && (
									<ArrowPathIcon className="animate-spin" />
								)}
							</Button>
						</div>
					</section>
				</DialogBody>

				<DialogActions>
					<Button type="button" plain onClick={() => onClose(false)}>
						Cancelar
					</Button>
					<Button type="submit" disabled={processing}>
						Guardar cambios
						{processing && <ArrowPathIcon className="animate-spin" />}
					</Button>
				</DialogActions>
			</form>
		</Dialog>
	);
}
