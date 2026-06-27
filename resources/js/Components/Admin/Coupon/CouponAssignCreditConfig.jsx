import { useCallback, useState } from "react";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Select } from "@/Components/Catalyst/select";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import { CREDIT_TYPE_OPTIONS } from "@/lib/couponAdminUi";
import { creditTypeMeta } from "@/lib/couponAssignCreditTypes";
import { generatePromoCode, normalizePromoCodeInput } from "@/lib/promoCodeGenerator";
import { SparklesIcon } from "@heroicons/react/16/solid";

function csrfTokenFromMeta() {
	if (typeof document === "undefined") return "";
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? "";
}

export default function CouponAssignCreditConfig({
	data,
	setData,
	errors = {},
	concepts = [],
	requireAuth = false,
	promoCodeConfig = {},
	onCreditTypeChange,
}) {
	const meta = creditTypeMeta(data.credit_type ?? "balance");
	const [checkingCode, setCheckingCode] = useState(false);
	const [codeAvailability, setCodeAvailability] = useState(null);

	const checkCodeAvailability = useCallback(async () => {
		const code = normalizePromoCodeInput(data.promo_code);
		if (!code) {
			setCodeAvailability(null);
			return;
		}
		setCheckingCode(true);
		try {
			const res = await fetch(route("admin.coupons.promo-codes.check-code"), {
				method: "POST",
				headers: {
					Accept: "application/json",
					"Content-Type": "application/json",
					"X-CSRF-TOKEN": csrfTokenFromMeta(),
					"X-Requested-With": "XMLHttpRequest",
				},
				body: JSON.stringify({ code }),
			});
			const json = await res.json();
			setCodeAvailability(json.available ? "available" : "taken");
		} catch {
			setCodeAvailability(null);
		} finally {
			setCheckingCode(false);
		}
	}, [data.promo_code]);

	const handleGenerateCode = () => {
		const generated = generatePromoCode(
			promoCodeConfig.code_prefix ?? "FAM",
			promoCodeConfig.code_segment_length ?? 4,
		);
		setData("auto_generate_promo_code", false);
		setData("promo_code", generated);
		setCodeAvailability(null);
	};

	return (
		<CouponSectionCard
			title={meta.title}
			description={meta.help}
			bodyClassName="space-y-6"
		>
			<div className="rounded-lg border border-sky-200 bg-sky-50/80 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
				{meta.help}
			</div>

			<Field>
				<Label>Tipo de beneficio</Label>
				<Select
					value={data.credit_type ?? "balance"}
					onChange={(e) => onCreditTypeChange?.(e.target.value)}
				>
					{CREDIT_TYPE_OPTIONS.map((option) => (
						<option key={option.value} value={option.value}>
							{option.label}
						</option>
					))}
				</Select>
				{errors.type && (
					<p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.type}</p>
				)}
			</Field>

			{data.credit_type === "shared_promo" && (
				<div className="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
					<div>
						<h3 className="text-sm font-semibold text-zinc-900 dark:text-white">Código promocional</h3>
						<p className="mt-1 text-xs text-zinc-500">
							Los usuarios lo ingresarán en checkout. Se normaliza a mayúsculas sin espacios.
						</p>
					</div>
					<div className="flex flex-col gap-3 sm:flex-row sm:items-end">
						<Field className="flex-1">
							<Label>Código</Label>
							<Input
								value={data.promo_code ?? ""}
								onChange={(e) => {
									setData("promo_code", normalizePromoCodeInput(e.target.value));
									setData("auto_generate_promo_code", false);
									setCodeAvailability(null);
								}}
								onBlur={checkCodeAvailability}
								placeholder="EVENTO-8F3K"
							/>
							{errors.code && (
								<p className="mt-1 text-sm text-red-600">{errors.code}</p>
							)}
							{codeAvailability === "available" && (
								<p className="mt-1 text-xs text-emerald-600">Código disponible.</p>
							)}
							{codeAvailability === "taken" && (
								<p className="mt-1 text-xs text-red-600">Ese código ya existe.</p>
							)}
						</Field>
						<Button type="button" outline onClick={handleGenerateCode} disabled={checkingCode}>
							<SparklesIcon className="size-4" />
							Generar código
						</Button>
					</div>
				</div>
			)}

			<div className="grid grid-cols-12 gap-4">
				<Field className="col-span-12 md:col-span-6">
					<Label>{meta.amountLabel}</Label>
					<Input
						type="number"
						step="0.01"
						min="0.01"
						value={data.amount_mxn}
						onChange={(e) => setData("amount_mxn", e.target.value)}
					/>
					{errors.amount_cents && (
						<p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.amount_cents}</p>
					)}
				</Field>

				{meta.showConcept && (
					<Field className="col-span-12 md:col-span-6">
						<Label>Concepto</Label>
						<Select
							value={data.coupon_concept_id ?? ""}
							onChange={(e) => {
								const v = e.target.value;
								setData("coupon_concept_id", v);
								if (v !== "other") setData("concept_other", "");
							}}
						>
							<option value="">Sin concepto</option>
							{concepts.map((c) => (
								<option key={c.id} value={String(c.id)}>
									{c.title}
								</option>
							))}
							<option value="other">Otra</option>
						</Select>
						{data.coupon_concept_id === "other" && (
							<div className="mt-2">
								<Input
									value={data.concept_other ?? ""}
									onChange={(e) => setData("concept_other", e.target.value)}
									placeholder="Especifica el concepto"
									maxLength={255}
								/>
							</div>
						)}
					</Field>
				)}

				<Field className="col-span-12">
					<Label>
						{data.credit_type === "balance"
							? "Motivo o descripción (opcional)"
							: "Descripción interna (opcional)"}
					</Label>
					<Textarea
						rows={2}
						value={data.description}
						onChange={(e) => setData("description", e.target.value)}
						placeholder={
							data.credit_type === "shared_promo"
								? "Campaña evento marzo, influencer X…"
								: undefined
						}
					/>
				</Field>
			</div>

			{data.credit_type === "balance" && (
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Este saldo se asigna directamente al usuario y podrá usarse como crédito disponible en
					su cuenta. No requiere vigencia ni código público.
				</Text>
			)}

			{requireAuth && data.credit_type !== "balance" && (
				<p className="text-sm text-amber-800 dark:text-amber-200">
					Con la política actual, el beneficio quedará pendiente hasta que el autorizador
					confirme por correo.
				</p>
			)}
		</CouponSectionCard>
	);
}

export function CouponAssignPromoUsageRules({ data, setData, errors = {} }) {
	return (
		<CouponSectionCard
			title="Reglas de uso del código"
			description="Define cuántas veces puede redimirse este código en total y por persona."
			bodyClassName="space-y-4"
		>
			<div className="grid gap-4 sm:grid-cols-2">
				<Field>
					<Label>Usos totales (max_redemptions)</Label>
					<Input
						type="number"
						min="1"
						value={data.max_redemptions ?? "100"}
						onChange={(e) => setData("max_redemptions", e.target.value)}
					/>
					<p className="mt-1 text-xs text-zinc-500">
						Número máximo de compras donde este código podrá aplicarse. Ej.: 10 para un evento.
					</p>
					{errors.max_redemptions && (
						<p className="mt-1 text-sm text-red-600">{errors.max_redemptions}</p>
					)}
				</Field>
				<Field>
					<Label>Usos por usuario (max_uses_per_user)</Label>
					<Input
						type="number"
						min="1"
						value={data.max_uses_per_user ?? "1"}
						onChange={(e) => setData("max_uses_per_user", e.target.value)}
					/>
					<p className="mt-1 text-xs text-zinc-500">
						Evita que una misma persona use el código más veces de lo permitido. Recomendado: 1.
					</p>
					{errors.max_uses_per_user && (
						<p className="mt-1 text-sm text-red-600">{errors.max_uses_per_user}</p>
					)}
				</Field>
			</div>
		</CouponSectionCard>
	);
}
