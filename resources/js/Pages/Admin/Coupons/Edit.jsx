import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { useForm } from "@inertiajs/react";
import { Badge } from "@/Components/Catalyst/badge";
import {
	appendCouponEligibilityToPayload,
	toDatetimeLocalValue,
} from "@/lib/couponEligibilityUi";

export default function CouponsEdit({ coupon }) {
	const { data, setData, put, processing, errors, transform } = useForm({
		code: coupon.code || "",
		description: coupon.description || "",
		max_beneficiaries:
			coupon.max_beneficiaries != null ? String(coupon.max_beneficiaries) : "",
		valid_from: toDatetimeLocalValue(coupon.valid_from),
		expires_at: toDatetimeLocalValue(coupon.expires_at),
		min_purchase_mxn:
			coupon.min_purchase_cents != null
				? String(coupon.min_purchase_cents / 100)
				: "",
		is_active: coupon.is_active,
	});

	transform((d) =>
		appendCouponEligibilityToPayload(
			{
				code: d.code || null,
				description: d.description || null,
				max_beneficiaries:
					String(d.max_beneficiaries ?? "").trim() === ""
						? null
						: parseInt(String(d.max_beneficiaries), 10),
				is_active: d.is_active,
			},
			d,
		),
	);

	const submit = (e) => {
		e.preventDefault();
		put(route("admin.coupons.update", coupon.id));
	};

	const statusLabel = {
		pending_authorization: "Pendiente de autorización",
		active: "Autorizado",
		rejected: "Rechazado",
	};

	return (
		<AdminLayout title="Editar cupón">
			<Heading>Editar cupón #{coupon.id}</Heading>
			<div className="mt-2 flex flex-wrap gap-2">
				<Badge
					color={
						coupon.approval_status === "pending_authorization"
							? "purple"
							: "emerald"
					}
				>
					{statusLabel[coupon.approval_status] ?? coupon.approval_status}
				</Badge>
			</div>
			<p className="mt-2 text-sm text-zinc-600">
				Monto por beneficiario:{" "}
				{(coupon.amount_cents / 100).toLocaleString("es-MX", {
					style: "currency",
					currency: "MXN",
				})}
			</p>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
				<Field>
					<Label>Descripción</Label>
					<Textarea
						rows={3}
						value={data.description}
						onChange={(e) => setData("description", e.target.value)}
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
					<Label>Código (opcional)</Label>
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
