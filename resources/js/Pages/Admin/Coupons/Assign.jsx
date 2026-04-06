import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { useForm } from "@inertiajs/react";

export default function CouponsAssign() {
	const { data, setData, post, processing, errors, transform } = useForm({
		email: "",
		amount_mxn: "",
		code: "",
		send_notification: true,
	});

	transform((d) => ({
		email: d.email,
		amount_cents: Math.round(
			parseFloat(String(d.amount_mxn).replace(",", "")) * 100,
		),
		code: d.code || null,
		send_notification: d.send_notification,
	}));

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.assign.store"));
	};

	return (
		<AdminLayout title="Asignar saldo">
			<Heading>Asignar saldo a usuario</Heading>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
				<Field>
					<Label>Correo del usuario</Label>
					<Input
						type="email"
						value={data.email}
						onChange={(e) => setData("email", e.target.value)}
					/>
					{errors.email && (
						<p className="text-sm text-red-600">{errors.email}</p>
					)}
				</Field>
				<Field>
					<Label>Monto (MXN)</Label>
					<Input
						type="number"
						step="0.01"
						min="0.01"
						value={data.amount_mxn}
						onChange={(e) => setData("amount_mxn", e.target.value)}
					/>
					{errors.amount_cents && (
						<p className="text-sm text-red-600">{errors.amount_cents}</p>
					)}
				</Field>
				<Field>
					<Label>Código (opcional)</Label>
					<Input
						value={data.code}
						onChange={(e) => setData("code", e.target.value)}
					/>
				</Field>
				<CheckboxField>
					<Checkbox
						checked={data.send_notification}
						onChange={(v) => setData("send_notification", v)}
					/>
					<Label>Enviar notificación (correo y aviso en plataforma)</Label>
				</CheckboxField>
				<div className="flex gap-2">
					<Button type="submit" disabled={processing} color="emerald">
						Asignar
					</Button>
					<Button href={route("admin.coupons.index")} plain>
						Volver
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
