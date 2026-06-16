import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { useForm } from "@inertiajs/react";
import {
	appendCouponEligibilityToPayload,
} from "@/lib/couponEligibilityUi";

export default function CouponsCreate({ settings }) {
	const requireAuth = !!settings?.require_authorization;

	const { data, setData, post, processing, errors, transform } = useForm({
		amount_mxn: "",
		code: "",
		description: "",
		max_beneficiaries: "",
		valid_from: "",
		expires_at: "",
		min_purchase_mxn: "",
		is_active: true,
	});

	transform((d) => {
		const cents = Math.round(
			parseFloat(String(d.amount_mxn).replace(",", "")) * 100,
		);
		const maxB = String(d.max_beneficiaries ?? "").trim();
		return appendCouponEligibilityToPayload(
			{
				amount_cents: cents,
				code: d.code || null,
				description: d.description || null,
				max_beneficiaries: maxB === "" ? null : parseInt(maxB, 10),
				is_active: d.is_active,
			},
			d,
		);
	});

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.store"));
	};

	return (
		<AdminLayout title="Nuevo cupón maestro">
			<Heading>Nuevo cupón maestro</Heading>
			<Text className="mt-2 text-zinc-600">
				Define el monto por beneficiario y cuántas personas pueden recibirlo. Luego
				asigna usuarios desde &quot;Asignar beneficiario&quot;.{" "}
				{requireAuth
					? "Con la política actual, este cupón quedará pendiente hasta autorizarlo con el código enviado al correo del autorizador."
					: ""}
			</Text>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
				<Field>
					<Label>Monto por beneficiario (MXN)</Label>
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
					<Label>Descripción (visible al asignar)</Label>
					<Textarea
						rows={3}
						value={data.description}
						onChange={(e) => setData("description", e.target.value)}
						placeholder="Ej. Campaña empleados Q2 — vigencia sujeta a políticas internas"
					/>
				</Field>
				<Field>
					<Label>Máximo de beneficiarios</Label>
					<Input
						type="number"
						min="1"
						placeholder="Sin límite"
						value={data.max_beneficiaries}
						onChange={(e) => setData("max_beneficiaries", e.target.value)}
					/>
					{errors.max_beneficiaries && (
						<p className="text-sm text-red-600">{errors.max_beneficiaries}</p>
					)}
				</Field>
				<Field>
					<Label>Código (opcional, se copia a cada asignación)</Label>
					<Input
						value={data.code}
						onChange={(e) => setData("code", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Disponible desde</Label>
					<Input
						type="datetime-local"
						value={data.valid_from}
						onChange={(e) => setData("valid_from", e.target.value)}
					/>
					<p className="mt-1 text-xs text-zinc-500">
						Déjalo vacío si el saldo está disponible inmediatamente.
					</p>
					{errors.valid_from && (
						<p className="text-sm text-red-600">{errors.valid_from}</p>
					)}
				</Field>
				<Field>
					<Label>Vence el</Label>
					<Input
						type="datetime-local"
						value={data.expires_at}
						onChange={(e) => setData("expires_at", e.target.value)}
					/>
					<p className="mt-1 text-xs text-zinc-500">
						Déjalo vacío si el saldo no vence.
					</p>
					{errors.expires_at && (
						<p className="text-sm text-red-600">{errors.expires_at}</p>
					)}
				</Field>
				<Field>
					<Label>Compra mínima requerida (MXN)</Label>
					<Input
						type="number"
						step="0.01"
						min="0"
						placeholder="Sin mínimo"
						value={data.min_purchase_mxn}
						onChange={(e) => setData("min_purchase_mxn", e.target.value)}
					/>
					<p className="mt-1 text-xs text-zinc-500">
						Déjalo vacío si no quieres exigir una compra mínima.
					</p>
					{errors.min_purchase_cents && (
						<p className="text-sm text-red-600">{errors.min_purchase_cents}</p>
					)}
				</Field>
				{!requireAuth && (
					<SwitchField>
						<Label>Activo al crear</Label>
						<Switch
							checked={data.is_active}
							onChange={(v) => setData("is_active", v)}
						/>
					</SwitchField>
				)}
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
