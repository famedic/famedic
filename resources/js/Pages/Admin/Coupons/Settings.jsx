import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { useForm } from "@inertiajs/react";

export default function CouponsSettings({ settings }) {
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
			<Heading>Reglas y seguridad — cupones saldo</Heading>
			<Text className="mt-2 max-w-2xl text-zinc-600">
				Define límites globales, el correo del autorizador y si cada nuevo cupón maestro
				debe confirmarse con un código enviado por correo antes de poder asignar
				beneficiarios.
			</Text>

			<form onSubmit={submit} className="mt-8 max-w-lg space-y-6">
				<div>
					<Subheading>Límites</Subheading>
					<Field className="mt-3">
						<Label>Monto máximo por cupón / asignación (MXN)</Label>
						<Input
							type="number"
							step="0.01"
							min="0"
							placeholder="Sin límite"
							value={data.max_assignment_amount_mxn}
							onChange={(e) =>
								setData("max_assignment_amount_mxn", e.target.value)
							}
						/>
						{errors.max_assignment_amount_mxn && (
							<p className="text-sm text-red-600">
								{errors.max_assignment_amount_mxn}
							</p>
						)}
					</Field>
					<Field className="mt-3">
						<Label>Máximo de asignaciones por día (total sistema)</Label>
						<Input
							type="number"
							min="1"
							placeholder="Sin límite"
							value={data.max_assignments_per_day}
							onChange={(e) =>
								setData("max_assignments_per_day", e.target.value)
							}
						/>
						{errors.max_assignments_per_day && (
							<p className="text-sm text-red-600">
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
							onChange={(e) => setData("authorization_email", e.target.value)}
						/>
						{errors.authorization_email && (
							<p className="text-sm text-red-600">{errors.authorization_email}</p>
						)}
					</Field>
					<CheckboxField className="mt-3">
						<Checkbox
							checked={data.require_authorization}
							onChange={(v) => setData("require_authorization", v)}
						/>
						<Label>
							Exigir código de autorización para nuevos cupones maestros
						</Label>
					</CheckboxField>
				</div>

				<div className="flex gap-2">
					<Button type="submit" disabled={processing} color="emerald">
						Guardar
					</Button>
					<Button href={route("admin.coupons.index")} plain>
						Volver
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
