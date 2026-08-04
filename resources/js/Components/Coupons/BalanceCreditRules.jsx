import {
	CalendarDaysIcon,
	ShoppingCartIcon,
	ExclamationTriangleIcon,
	CheckCircleIcon,
	InformationCircleIcon,
} from "@heroicons/react/24/outline";
import { Text } from "@/Components/Catalyst/text";
import {
	buildBalanceCreditTermsRows,
	formatCouponMoney,
	getAmountMissingForMinimum,
} from "@/lib/couponPatientUi";

const toneClasses = {
	default: "text-zinc-800 dark:text-zinc-200",
	warning: "font-semibold text-amber-700 dark:text-amber-300",
	success: "font-medium text-emerald-700 dark:text-emerald-300",
	info: "text-sky-700 dark:text-sky-300",
};

const iconForRow = (key) => {
	switch (key) {
		case "expires_at":
		case "valid_from":
		case "no_expiry":
			return CalendarDaysIcon;
		case "min_purchase":
		case "no_min":
			return ShoppingCartIcon;
		case "shortfall":
		case "too_large":
			return ExclamationTriangleIcon;
		case "applicable":
			return CheckCircleIcon;
		default:
			return InformationCircleIcon;
	}
};

function TermsRow({ row }) {
	const Icon = iconForRow(row.key);
	const valueClass = toneClasses[row.tone ?? "default"];
	const isFootnote = row.key === "conditions";

	return (
		<div
			className={[
				"flex min-w-0 items-start gap-2.5 py-2.5",
				isFootnote ? "pt-1" : "",
			].join(" ")}
		>
			<Icon
				className="mt-0.5 size-4 shrink-0 text-violet-400 dark:text-violet-500"
				aria-hidden="true"
			/>
			<div className="min-w-0 flex-1">
				{!isFootnote && (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						{row.label}
					</Text>
				)}
				<Text
					className={[
						"break-words text-xs leading-snug sm:text-sm",
						isFootnote ? "text-zinc-600 dark:text-zinc-300" : valueClass,
						!isFootnote ? "mt-0.5" : "",
					].join(" ")}
				>
					{row.value}
				</Text>
			</div>
		</div>
	);
}

function TermsAlert({ reason, coupon, cartTotalCents }) {
	if (reason !== "below_minimum" && reason !== "balance_too_large") {
		return null;
	}

	const shortfall = getAmountMissingForMinimum(coupon, cartTotalCents);
	const message =
		reason === "below_minimum" && shortfall > 0
			? `Te faltan ${formatCouponMoney(shortfall)} para usar tu saldo en esta compra.`
			: reason === "balance_too_large"
				? "El saldo es mayor que el total de esta compra."
				: null;

	if (!message) return null;

	return (
		<div className="mb-2 flex items-start gap-2 rounded-md border border-amber-200/80 bg-amber-50/80 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
			<ExclamationTriangleIcon
				className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
				aria-hidden="true"
			/>
			<Text className="text-xs leading-snug text-amber-800 dark:text-amber-200">
				{message}
			</Text>
		</div>
	);
}

export default function BalanceCreditRules({
	coupon,
	cartTotalCents,
	primaryReason,
	applied = false,
	expanded = false,
	className = "",
}) {
	if (!coupon || !expanded) return null;

	const rows = buildBalanceCreditTermsRows(
		coupon,
		cartTotalCents,
		primaryReason,
		applied,
	);

	if (rows.length === 0) return null;

	return (
		<div
			className={[
				"min-w-0 overflow-hidden rounded-lg border border-violet-100/90 bg-white/70 px-3 py-1 dark:border-violet-900/30 dark:bg-zinc-900/50",
				className,
			].join(" ")}
		>
			<TermsAlert
				reason={primaryReason}
				coupon={coupon}
				cartTotalCents={cartTotalCents}
			/>
			<div className="divide-y divide-violet-100/70 dark:divide-violet-900/30">
				{rows.map((row) => (
					<TermsRow key={row.key} row={row} />
				))}
			</div>
		</div>
	);
}
