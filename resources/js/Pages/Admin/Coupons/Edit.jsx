import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import CouponEligibilityControls from "@/Components/Admin/Coupon/CouponEligibilityControls";
import { useForm } from "@inertiajs/react";
import { Badge } from "@/Components/Catalyst/badge";
import {
	appendCouponEligibilityToPayload,
	inferMinimumPurchaseMode,
	inferValidityMode,
	toDatetimeLocalValue,
} from "@/lib/couponEligibilityUi";

export default function CouponsEdit({ coupon }) {
	const initialValidFrom = toDatetimeLocalValue(coupon.valid_from);
	const initialExpiresAt = toDatetimeLocalValue(coupon.expires_at);
	const initialMinPurchaseMxn =
		coupon.min_purchase_cents != null
			? String(coupon.min_purchase_cents / 100)
			: "";

	const { data, setData, put, processing, errors, transform } = useForm({
		code: coupon.code || "",
		description: coupon.description || "",
		max_beneficiaries:
			coupon.max_beneficiaries != null ? String(coupon.max_beneficiaries) : "",
		validity_mode: inferValidityMode(initialValidFrom, initialExpiresAt),
		valid_from: initialValidFrom,
		expires_at: initialExpiresAt,
		minimum_purchase_mode: inferMinimumPurchaseMode(initialMinPurchaseMxn),
		min_purchase_mxn: initialMinPurchaseMxn,
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
				<CouponEligibilityControls
					data={data}
					setData={setData}
					errors={errors}
					embedded
				/>
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
