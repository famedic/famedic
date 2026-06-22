import clsx from "clsx";
import { useEffect, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import Card from "@/Components/Card";
import {
	couponCreditTypeLabel,
	couponDiscountCents,
	formatCreditExpiryCountdown,
	getCreditExpiryCountdown,
	isCouponApplicableForCheckout,
} from "@/lib/couponEligibilityUi";

function formatMxnFromCents(cents) {
	return (cents / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

function CreditExpiryCountdown({ expiresAt }) {
	const [countdown, setCountdown] = useState(() => getCreditExpiryCountdown(expiresAt));

	useEffect(() => {
		if (!expiresAt) return undefined;
		const tick = () => setCountdown(getCreditExpiryCountdown(expiresAt));
		tick();
		const intervalId = setInterval(tick, 60_000);
		return () => clearInterval(intervalId);
	}, [expiresAt]);

	const formatted = formatCreditExpiryCountdown(countdown);
	if (!formatted) return null;

	return (
		<Text className="text-xs font-medium text-sky-800 dark:text-sky-200">
			Te quedan {formatted} para aprovecharlo.
		</Text>
	);
}

function CreditOptionRow({
	credit,
	cartTotalCents,
	variant,
	selected,
	onSelect,
	onClear,
}) {
	const applicable = isCouponApplicableForCheckout(credit, cartTotalCents);
	const discountCents = couponDiscountCents(credit, cartTotalCents);
	const typeLabel = couponCreditTypeLabel(credit);
	const isCheckout = variant === "checkout";
	const isSelected = selected && selected === credit.id;

	return (
		<div
			className={clsx(
				"rounded-lg border p-3 transition-colors",
				isSelected
					? "border-famedic-dark/40 bg-famedic-dark/5 ring-1 ring-famedic-dark/20 dark:border-famedic-lime/40 dark:bg-famedic-lime/5 dark:ring-famedic-lime/30"
					: "border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40",
				isCheckout && applicable && !isSelected && "hover:border-zinc-300 dark:hover:border-zinc-600",
			)}
		>
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div className="min-w-0 space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<Badge color={credit.type === "coupon" ? "cyan" : "emerald"}>
							{typeLabel}
						</Badge>
						{credit.is_recommended ? (
							<Badge color="amber">Recomendado</Badge>
						) : null}
						{credit.code ? (
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								Código: {credit.code}
							</Text>
						) : null}
					</div>
					<Text className="font-semibold text-zinc-900 dark:text-white">
						{credit.formatted_remaining ?? formatMxnFromCents(credit.remaining_cents)}
					</Text>
					{credit.expires_at ? (
						<CreditExpiryCountdown expiresAt={credit.expires_at} />
					) : null}
					{credit.label ? (
						<Text
							className={clsx(
								"text-sm",
								applicable
									? "text-emerald-700 dark:text-emerald-300"
									: "text-amber-700 dark:text-amber-300",
							)}
						>
							{credit.label}
						</Text>
					) : null}
					{!applicable && credit.formatted_missing_for_minimum ? (
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Faltan {credit.formatted_missing_for_minimum} para la compra mínima.
						</Text>
					) : null}
					{applicable && discountCents < credit.remaining_cents ? (
						<Text className="text-xs text-zinc-600 dark:text-zinc-400">
							Descuento en esta compra: {formatMxnFromCents(discountCents)}
						</Text>
					) : null}
				</div>
				{isCheckout ? (
					<div className="flex shrink-0 flex-col gap-2 sm:flex-row">
						{isSelected ? (
							<Button type="button" plain onClick={() => onClear?.()}>
								Cancelar
							</Button>
						) : (
							<Button
								type="button"
								outline
								disabled={!applicable}
								onClick={() => onSelect?.(credit.id)}
							>
								Usar
							</Button>
						)}
					</div>
				) : null}
			</div>
		</div>
	);
}

export default function BalanceCreditCard({
	variant = "cart",
	balanceCreditPresentation = null,
	balanceCouponsCents = 0,
	availableBalanceCoupons = [],
	cartTotalCents = 0,
	selectedCouponId = null,
	onApply,
	onClear,
}) {
	const credits =
		balanceCreditPresentation?.coupons?.length > 0
			? balanceCreditPresentation.coupons
			: availableBalanceCoupons;

	if (!credits?.length) {
		return null;
	}

	const balanceCredits = credits.filter((c) => (c.type ?? "balance") === "balance");
	const couponCredits = credits.filter((c) => c.type === "coupon");
	const totalCents =
		balanceCreditPresentation?.total_balance_cents ?? balanceCouponsCents;
	const applicableCount =
		balanceCreditPresentation?.applicable_coupons_count ??
		credits.filter((c) => isCouponApplicableForCheckout(c, cartTotalCents)).length;

	return (
		<Card className="p-4">
			<div className="mb-3 flex flex-wrap items-end justify-between gap-2">
				<div>
					<Subheading>Créditos disponibles</Subheading>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
						{variant === "checkout"
							? "Elige un saldo a favor o un cupón para esta compra."
							: "Tienes créditos que podrás usar en el checkout."}
					</Text>
				</div>
				{totalCents > 0 ? (
					<Text className="text-sm font-semibold text-zinc-900 dark:text-white">
						Total: {formatMxnFromCents(totalCents)}
					</Text>
				) : null}
			</div>

			{variant === "checkout" && applicableCount === 0 ? (
				<Text className="mb-3 text-sm text-amber-700 dark:text-amber-300">
					Ningún crédito aplica con el total actual del carrito. Revisa compra
					mínima, vigencia o monto del saldo.
				</Text>
			) : null}

			<div className="space-y-4">
				{balanceCredits.length > 0 ? (
					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Saldos a favor
						</Text>
						{balanceCredits.map((credit) => (
							<CreditOptionRow
								key={credit.id}
								credit={credit}
								cartTotalCents={cartTotalCents}
								variant={variant}
								selected={selectedCouponId}
								onSelect={onApply}
								onClear={onClear}
							/>
						))}
					</div>
				) : null}

				{couponCredits.length > 0 ? (
					<div className="space-y-2">
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							Cupones
						</Text>
						{couponCredits.map((credit) => (
							<CreditOptionRow
								key={credit.id}
								credit={credit}
								cartTotalCents={cartTotalCents}
								variant={variant}
								selected={selectedCouponId}
								onSelect={onApply}
								onClear={onClear}
							/>
						))}
					</div>
				) : null}
			</div>
		</Card>
	);
}
