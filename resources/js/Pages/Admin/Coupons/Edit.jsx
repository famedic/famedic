import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { useForm } from "@inertiajs/react";

export default function CouponsEdit({ coupon }) {
	const { data, setData, put, processing, errors } = useForm({
		code: coupon.code || "",
		is_active: coupon.is_active,
	});

	const submit = (e) => {
		e.preventDefault();
		put(route("admin.coupons.update", coupon.id));
	};

	return (
		<AdminLayout title="Editar cupón">
			<Heading>Editar cupón #{coupon.id}</Heading>
			<p className="mt-2 text-sm text-zinc-600">
				Monto original:{" "}
				{(coupon.amount_cents / 100).toLocaleString("es-MX", {
					style: "currency",
					currency: "MXN",
				})}{" "}
				· Restante:{" "}
				{(coupon.remaining_cents / 100).toLocaleString("es-MX", {
					style: "currency",
					currency: "MXN",
				})}
			</p>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
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
						Volver
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
