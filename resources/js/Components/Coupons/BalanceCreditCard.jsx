import clsx from "clsx";
import { useEffect, useState } from "react";
import { ChevronDownIcon, ClockIcon, CheckIcon } from "@heroicons/react/24/outline";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import Card from "@/Components/Card";
import {
	couponCreditTypeLabel,
	couponDiscountCents,
	getCreditExpiryCountdown,
	isCouponApplicableForCheckout,
} from "@/lib/couponEligibilityUi";

function formatMxnFromCents(cents) {
	return (cents / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

function formatMinPurchaseLabel(credit) {
	if (credit?.formatted_min_purchase) {
		return credit.formatted_min_purchase;
	}
	if (credit?.min_purchase_cents > 0) {
		return formatMxnFromCents(credit.min_purchase_cents);
	}
	return null;
}

function countdownUrgency(countdown) {
	if (!countdown || countdown.expired) return "calm";
	const totalHours = countdown.days * 24 + countdown.hours;
	if (totalHours <= 24) return "urgent";
	if (countdown.days <= 3) return "soon";
	return "calm";
}

const countdownStyles = {
	calm: {
		wrap: "border-sky-200/90 bg-gradient-to-r from-sky-50 to-cyan-50 dark:border-sky-700/60 dark:from-sky-950/50 dark:to-cyan-950/30",
		icon: "text-sky-600 dark:text-sky-300",
		label: "text-sky-900 dark:text-sky-100",
		pill: "border-sky-200/80 bg-white/90 text-sky-900 dark:border-sky-600/50 dark:bg-sky-950/60 dark:text-sky-100",
		hint: "text-sky-800/80 dark:text-sky-200/80",
	},
	soon: {
		wrap: "border-amber-200/90 bg-gradient-to-r from-amber-50 to-orange-50 dark:border-amber-700/60 dark:from-amber-950/40 dark:to-orange-950/30",
		icon: "text-amber-600 dark:text-amber-300",
		label: "text-amber-950 dark:text-amber-100",
		pill: "border-amber-200/80 bg-white/90 text-amber-950 dark:border-amber-600/50 dark:bg-amber-950/60 dark:text-amber-100",
		hint: "text-amber-900/80 dark:text-amber-200/80",
	},
	urgent: {
		wrap: "border-rose-200/90 bg-gradient-to-r from-rose-50 to-orange-50 dark:border-rose-700/60 dark:from-rose-950/40 dark:to-orange-950/30",
		icon: "text-rose-600 dark:text-rose-300",
		label: "text-rose-950 dark:text-rose-100",
		pill: "border-rose-200/80 bg-white/90 text-rose-950 dark:border-rose-600/50 dark:bg-rose-950/60 dark:text-rose-100",
		hint: "text-rose-900/80 dark:text-rose-200/80",
	},
};

function CreditExpiryCountdown({ expiresAt }) {
	const [countdown, setCountdown] = useState(() => getCreditExpiryCountdown(expiresAt));

	useEffect(() => {
		if (!expiresAt) return undefined;
		const tick = () => setCountdown(getCreditExpiryCountdown(expiresAt));
		tick();
		const intervalId = setInterval(tick, 60_000);
		return () => clearInterval(intervalId);
	}, [expiresAt]);

	if (!countdown || countdown.expired) return null;

	const urgency = countdownUrgency(countdown);
	const styles = countdownStyles[urgency];
	const segments = [];

	if (countdown.days > 0) {
		segments.push({
			value: countdown.days,
			label: countdown.days === 1 ? "día" : "días",
		});
	}

	segments.push({
		value: countdown.hours,
		label: countdown.hours === 1 ? "hora" : "horas",
	});

	return (
		<div
			className={clsx(
				"mt-1 rounded-lg border px-3 py-2.5 shadow-sm",
				styles.wrap,
			)}
			role="status"
			aria-live="polite"
		>
			<div className="flex items-center gap-2">
				<ClockIcon className={clsx("size-4 shrink-0", styles.icon)} aria-hidden />
				<Text className={clsx("text-xs font-semibold uppercase tracking-wide", styles.label)}>
					Tiempo restante
				</Text>
			</div>
			<div className="mt-2 flex flex-wrap items-center gap-2">
				{segments.map((segment) => (
					<span
						key={segment.label}
						className={clsx(
							"inline-flex min-w-[4.5rem] flex-col items-center rounded-md border px-2.5 py-1.5 text-center shadow-sm",
							styles.pill,
						)}
					>
						<span className="text-lg font-bold leading-none tabular-nums">
							{segment.value}
						</span>
						<span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide opacity-80">
							{segment.label}
						</span>
					</span>
				))}
			</div>
			<Text className={clsx("mt-2 text-xs font-medium", styles.hint)}>
				Para aprovechar este crédito antes de que venza.
			</Text>
		</div>
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
	const minPurchaseLabel = formatMinPurchaseLabel(credit);
	const hasMinPurchase = Boolean(minPurchaseLabel);

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
						{credit.concept ? (
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								Concepto: {credit.concept}
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
					{hasMinPurchase ? (
						<div className="rounded-md border border-amber-200/80 bg-amber-50/70 px-2.5 py-2 dark:border-amber-800/50 dark:bg-amber-950/25">
							<Text className="text-xs font-semibold text-amber-950 dark:text-amber-100">
								Compra mínima requerida: {minPurchaseLabel}
							</Text>
							{!applicable && credit.formatted_missing_for_minimum ? (
								<Text className="mt-1 text-xs text-amber-800 dark:text-amber-200">
									Te faltan {credit.formatted_missing_for_minimum} en tu carrito
									para poder usarlo.
								</Text>
							) : null}
						</div>
					) : null}
					{applicable && discountCents < credit.remaining_cents ? (
						<Text className="text-xs text-zinc-600 dark:text-zinc-400">
							Descuento en esta compra: {formatMxnFromCents(discountCents)}
						</Text>
					) : null}
				</div>
				{isCheckout ? (
					<div className="flex w-full shrink-0 flex-col gap-2 sm:w-auto sm:min-w-[7.5rem]">
						{isSelected ? (
							<Button type="button" plain onClick={() => onClear?.()}>
								Cancelar
							</Button>
						) : (
							<Button
								type="button"
								color="sky"
								disabled={!applicable}
								className="w-full shadow-md sm:min-w-[7.5rem]"
								onClick={() => onSelect?.(credit.id)}
							>
								<CheckIcon className="size-4" data-slot="icon" aria-hidden />
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
	const [creditsExpanded, setCreditsExpanded] = useState(false);
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
			<div className="mb-3">
				<div className="flex flex-wrap items-end justify-between gap-2">
					<div>
						<Subheading>Tienes créditos disponibles</Subheading>
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

				{variant === "checkout" ? (
					<div className="mt-2 flex items-center">
						<button
							type="button"
							onClick={() => setCreditsExpanded((open) => !open)}
							aria-expanded={creditsExpanded}
							aria-controls="checkout-credits-list"
							className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
						>
							<ChevronDownIcon
								className={clsx(
									"size-4 shrink-0 transition-transform duration-200",
									creditsExpanded && "-rotate-180",
								)}
								aria-hidden
							/>
							{creditsExpanded ? "Ocultar" : "Mostrar"}
						</button>
					</div>
				) : null}
			</div>

			{variant === "checkout" && applicableCount === 0 && creditsExpanded ? (
				<Text className="mb-3 text-sm text-amber-700 dark:text-amber-300">
					Ningún crédito aplica con el total actual del carrito. Revisa compra
					mínima, vigencia o monto del saldo.
				</Text>
			) : null}

			{creditsExpanded ? (
			<div id="checkout-credits-list" className="space-y-4">
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
			) : null}
		</Card>
	);
}
