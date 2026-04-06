import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { useForm } from "@inertiajs/react";

export default function CouponsCreate() {
	const { data, setData, post, processing, errors, transform } = useForm({
		amount_mxn: "",
		code: "",
		is_active: true,
	});

	transform((d) => {
		const cents = Math.round(
			parseFloat(String(d.amount_mxn).replace(",", "")) * 100,
		);
		return {
			amount_cents: cents,
			code: d.code || null,
			is_active: d.is_active,
		};
	});

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.store"));
	};

	return (
		<AdminLayout title="Nuevo cupón">
			<Heading>Nuevo cupón (sin asignar)</Heading>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
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
				<SwitchField>
					<Label>Activo</Label>
					<Switch
						checked={data.is_active}
						onChange={(v) => setData("is_active", v)}
					/>
				</SwitchField>
				<div className="flex gap-2">
					<Button type="submit" disabled={processing} color="emerald">
						Guardar
					</Button>
					<Button href={route("admin.coupons.index")} plain>
						Cancelar
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
