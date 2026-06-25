import { useMemo, useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponEligibilityControls from "@/Components/Admin/Coupon/CouponEligibilityControls";
import CouponCreationOtpModal from "@/Components/Admin/Coupon/CouponCreationOtpModal";
import CouponOtpSecurityNotice from "@/Components/Admin/Coupon/CouponOtpSecurityNotice";
import {
	appendCouponEligibilityToPayload,
	currentDatetimeLocalValue,
	isCouponEligibilityFormComplete,
} from "@/lib/couponEligibilityUi";

function buildPromoPayload(d) {
	const amountMxn = parseFloat(String(d.amount_mxn).replace(",", ""));
	const out = {
		promo_creation: true,
		promo_type: "shared",
		auto_generate_code: d.auto_generate_code,
		code: d.auto_generate_code ? null : (d.code?.trim().toUpperCase() ?? ""),
		description: d.description?.trim() ? d.description.trim() : null,
		amount_cents: Number.isNaN(amountMxn) ? 0 : Math.round(amountMxn * 100),
		max_redemptions: parseInt(String(d.max_redemptions), 10),
		max_uses_per_user: parseInt(String(d.max_uses_per_user), 10),
		is_active: d.is_active,
	};
	appendCouponEligibilityToPayload(out, d);
	return out;
}

export default function PromoCodesCreate({
	creationOtpRequired = true,
	rulesForUi = {},
}) {
	const otpVerificationTokenRef = useRef(null);
	const [otpModalOpen, setOtpModalOpen] = useState(false);

	const { data, setData, post, processing, errors, transform } = useForm({
		code: "",
		auto_generate_code: false,
		description: "",
		amount_mxn: "100",
		max_redemptions: "1",
		max_uses_per_user: "1",
		is_active: true,
		validity_mode: "configured",
		minimum_purchase_mode: "required",
		valid_from: currentDatetimeLocalValue(),
		expires_at: currentDatetimeLocalValue(30),
		min_purchase_mxn: "1000",
	});

	transform((d) => {
		const out = buildPromoPayload(d);
		if (otpVerificationTokenRef.current) {
			out.otp_verification_token = otpVerificationTokenRef.current;
		}
		return out;
	});

	const otpAssignPayload = useMemo(() => buildPromoPayload(data), [data]);

	const formComplete = useMemo(() => {
		const amount = parseFloat(String(data.amount_mxn).replace(",", ""));
		if (Number.isNaN(amount) || amount <= 0) return false;
		if (!data.auto_generate_code && !String(data.code ?? "").trim()) return false;
		const maxRedemptions = parseInt(String(data.max_redemptions), 10);
		if (!maxRedemptions || maxRedemptions < 1) return false;
		return isCouponEligibilityFormComplete(data);
	}, [data]);

	const submitCreate = () => {
		post(route("admin.coupons.promo-codes.store"));
	};

	const handleOtpVerified = (result) => {
		otpVerificationTokenRef.current = result.verification_token;
		setOtpModalOpen(false);
		submitCreate();
	};

	const handleSubmit = (e) => {
		e.preventDefault();
		if (!formComplete || processing) return;
		if (creationOtpRequired) {
			setOtpModalOpen(true);
			return;
		}
		submitCreate();
	};

	const maxAmountHint =
		rulesForUi?.max_assignment_amount_mxn != null
			? `Máximo permitido: $${Number(rulesForUi.max_assignment_amount_mxn).toLocaleString("es-MX")} MXN`
			: null;

	return (
		<AdminLayout title="Crear código promocional">
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Crear código promocional compartido</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Genera un cupón maestro de descuento y un código reutilizable para checkout.
						</Text>
						{creationOtpRequired && (
							<CouponOtpSecurityNotice
								required={creationOtpRequired}
								compact
								className="mt-3"
							/>
						)}
					</div>
					<Button href={route("admin.coupons.promo-codes.index")} outline>
						Volver al listado
					</Button>
				</div>

				<form onSubmit={handleSubmit} className="space-y-6">
					<CouponSectionCard title="Código y descuento">
						<div className="space-y-4">
							<CheckboxField>
								<Checkbox
									checked={data.auto_generate_code}
									onChange={(checked) => setData("auto_generate_code", checked)}
								/>
								<Label>Generar código automáticamente</Label>
							</CheckboxField>

							{!data.auto_generate_code && (
								<Field>
									<Label>Código promocional</Label>
									<Input
										value={data.code}
										className="uppercase"
										autoCapitalize="characters"
										autoCorrect="off"
										spellCheck={false}
										onChange={(e) => setData("code", e.target.value.toUpperCase())}
										onPaste={(e) => {
											e.preventDefault();
											const pasted = (e.clipboardData.getData("text") || "").toUpperCase();
											setData("code", pasted);
										}}
										placeholder="EVENTO10"
									/>
									{errors.code && (
										<p className="mt-1 text-sm text-red-600">{errors.code}</p>
									)}
								</Field>
							)}

							<Field>
								<Label>Descripción interna</Label>
								<Textarea
									value={data.description}
									onChange={(e) => setData("description", e.target.value)}
									rows={2}
									placeholder="Campaña evento marzo"
								/>
								{errors.description && (
									<p className="mt-1 text-sm text-red-600">{errors.description}</p>
								)}
							</Field>

							<Field>
								<Label>Monto de descuento (MXN)</Label>
								<Input
									type="number"
									min="0.01"
									step="0.01"
									value={data.amount_mxn}
									onChange={(e) => setData("amount_mxn", e.target.value)}
								/>
								{maxAmountHint && (
									<p className="mt-1 text-xs text-zinc-500">{maxAmountHint}</p>
								)}
								{errors.amount_cents && (
									<p className="mt-1 text-sm text-red-600">{errors.amount_cents}</p>
								)}
							</Field>

							<Field className="max-w-xs">
								<Label>Número de usos (beneficiarios)</Label>
								<Input
									type="number"
									min="1"
									step="1"
									value={data.max_redemptions}
									onChange={(e) => setData("max_redemptions", e.target.value)}
								/>
								{errors.max_redemptions && (
									<p className="mt-1 text-sm text-red-600">{errors.max_redemptions}</p>
								)}
							</Field>

							<CheckboxField>
								<Checkbox
									checked={data.is_active}
									onChange={(checked) => setData("is_active", checked)}
								/>
								<Label>Activo al crear</Label>
							</CheckboxField>
						</div>
					</CouponSectionCard>

					<CouponSectionCard title="Reglas de uso">
						<CouponEligibilityControls data={data} setData={setData} errors={errors} embedded />
					</CouponSectionCard>

					{errors.otp_verification_token && (
						<p className="text-sm text-red-600">{errors.otp_verification_token}</p>
					)}

					<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
						{creationOtpRequired && (
							<CouponOtpSecurityNotice required={creationOtpRequired} compact className="max-w-md" />
						)}
						<div className="flex justify-end gap-3 sm:ml-auto">
							<Button href={route("admin.coupons.promo-codes.index")} outline type="button">
								Cancelar
							</Button>
							<Button type="submit" color="lime" disabled={!formComplete || processing}>
								{processing ? "Guardando…" : "Crear código"}
							</Button>
						</div>
					</div>
				</form>
			</div>

			<CouponCreationOtpModal
				isOpen={otpModalOpen}
				assignPayload={otpAssignPayload}
				onSuccess={handleOtpVerified}
				onClose={() => setOtpModalOpen(false)}
			/>
		</AdminLayout>
	);
}
