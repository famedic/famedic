import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Badge } from "@/Components/Catalyst/badge";
import { useForm } from "@inertiajs/react";

export default function CouponsSettings({ settings, authorizers = [] }) {
	const { data, setData, put, processing, errors } = useForm({
		max_assignment_amount_mxn:
			settings.max_assignment_amount_cents != null
				? String(settings.max_assignment_amount_cents / 100)
				: "",
		max_assignments_per_day: settings.max_assignments_per_day ?? "",
		authorization_email: settings.authorization_email ?? "",
		require_authorization: !!settings.require_authorization,
	});

	const submit = (e) => {
		e.preventDefault();
		put(route("admin.coupons.settings.update"));
	};

	return (
		<AdminLayout title="Reglas de cupones">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div>
					<Heading>Reglas y seguridad - cupones saldo</Heading>
					<Text className="mt-2 max-w-2xl text-zinc-600">
						Define límites globales, el correo del autorizador y si
						cada nuevo cupón maestro debe confirmarse con un código
						enviado por correo antes de poder asignar beneficiarios.
					</Text>
				</div>
				<Button href={route("admin.coupons.index")} plain>
					Volver
				</Button>
			</div>

			<div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]">
				<form
					onSubmit={submit}
					className="space-y-6 rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div>
						<Subheading>Configuración</Subheading>
						<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
							Estos valores aplican a la creación y autorización
							de cupones maestros.
						</Text>
					</div>

					<div>
						<Subheading>Límites</Subheading>
						<Field className="mt-3">
							<Label>
								Monto máximo por cupón / asignación (MXN)
							</Label>
							<Input
								type="number"
								step="0.01"
								min="0"
								placeholder="Sin límite"
								value={data.max_assignment_amount_mxn}
								onChange={(e) =>
									setData(
										"max_assignment_amount_mxn",
										e.target.value,
									)
								}
							/>
							{errors.max_assignment_amount_mxn && (
								<p className="text-sm text-red-600 dark:text-red-400">
									{errors.max_assignment_amount_mxn}
								</p>
							)}
						</Field>
						<Field className="mt-3">
							<Label>
								Máximo de asignaciones por día (total sistema)
							</Label>
							<Input
								type="number"
								min="1"
								placeholder="Sin límite"
								value={data.max_assignments_per_day}
								onChange={(e) =>
									setData(
										"max_assignments_per_day",
										e.target.value,
									)
								}
							/>
							{errors.max_assignments_per_day && (
								<p className="text-sm text-red-600 dark:text-red-400">
									{errors.max_assignments_per_day}
								</p>
							)}
						</Field>
					</div>

					<div>
						<Subheading>Autorización por correo</Subheading>
						<Field className="mt-3">
							<Label>Correo del autorizador</Label>
							<Input
								type="email"
								placeholder="autorizador@empresa.com"
								value={data.authorization_email}
								onChange={(e) =>
									setData(
										"authorization_email",
										e.target.value,
									)
								}
							/>
							{errors.authorization_email && (
								<p className="text-sm text-red-600 dark:text-red-400">
									{errors.authorization_email}
								</p>
							)}
						</Field>
						<CheckboxField className="mt-3">
							<Checkbox
								checked={data.require_authorization}
								onChange={(v) =>
									setData("require_authorization", v)
								}
							/>
							<Label>
								Exigir código de autorización para nuevos
								cupones maestros
							</Label>
						</CheckboxField>
					</div>

					<div className="flex gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-700">
						<Button
							type="submit"
							disabled={processing}
							color="emerald"
						>
							Guardar
						</Button>
					</div>
				</form>

				<div className="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3">
						<div>
							<Subheading>Autorizadores</Subheading>
							<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
								Administradores con rol autorizador.
							</Text>
						</div>
						<Badge color="emerald">{authorizers.length}</Badge>
					</div>

					{authorizers.length > 0 ? (
						<div className="mt-5 divide-y divide-zinc-200 dark:divide-zinc-800">
							{authorizers.map((authorizer) => (
								<div
									key={authorizer.id}
									className="py-3 first:pt-0 last:pb-0"
								>
									<p className="font-medium text-zinc-950 dark:text-white">
										{authorizer.name}
									</p>
									<p className="mt-0.5 break-all text-sm text-zinc-600 dark:text-zinc-400">
										{authorizer.email || "Sin correo"}
									</p>
								</div>
							))}
						</div>
					) : (
						<div className="mt-5 rounded-lg border border-dashed border-zinc-300 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">
							No hay administradores con rol autorizador.
						</div>
					)}
				</div>
			</div>
		</AdminLayout>
	);
}
