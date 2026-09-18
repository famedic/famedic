export function formatCheckoutStudyCountLabel(count) {
	const safeCount = Math.max(0, Number(count) || 0);

	if (safeCount === 1) {
		return "1 estudio";
	}

	return `${safeCount} estudios`;
}

export function parseFormattedPriceToCents(formattedPrice) {
	if (!formattedPrice) {
		return 0;
	}

	const digits = String(formattedPrice).replace(/[^0-9]/g, "");

	if (!digits) {
		return 0;
	}

	return Number.parseInt(digits, 10);
}

export function computeDiscountPercent(originalCents, currentCents) {
	const original = Math.max(0, Number(originalCents) || 0);
	const current = Math.max(0, Number(currentCents) || 0);

	if (original <= 0 || current >= original) {
		return null;
	}

	return Math.round(((original - current) / original) * 100);
}

export function formatDiscountBadge(percent) {
	if (percent === null || percent <= 0) {
		return null;
	}

	return `-${percent}%`;
}

export function hasRealCheckoutItemDiscount(item) {
	const publicCents =
		Number(item?.publicPriceCents) ||
		parseFormattedPriceToCents(item?.discountedPrice);
	const famedicCents =
		Number(item?.famedicPriceCents) ||
		parseFormattedPriceToCents(item?.price);

	return publicCents > 0 && famedicCents > 0 && publicCents > famedicCents;
}

export function buildCheckoutItemDiscountPresentation(item) {
	const publicCents =
		Number(item?.publicPriceCents) ||
		parseFormattedPriceToCents(item?.discountedPrice);
	const famedicCents =
		Number(item?.famedicPriceCents) ||
		parseFormattedPriceToCents(item?.price);
	const percentFromCents = computeDiscountPercent(publicCents, famedicCents);
	const percentFromItem =
		Number.isFinite(Number(item?.discountPercentage)) &&
		Number(item?.discountPercentage) > 0
			? Math.round(Number(item.discountPercentage))
			: null;
	const percent = percentFromCents ?? percentFromItem;

	return {
		showOriginalPrice: hasRealCheckoutItemDiscount(item),
		formattedOriginalPrice: item?.discountedPrice ?? null,
		formattedCurrentPrice: item?.price ?? null,
		discountBadge: formatDiscountBadge(percent),
		discountPercent: percent,
		removeAriaLabel: "Eliminar estudio",
	};
}

export function hasDiscountValue(formattedDiscount) {
	if (!formattedDiscount) {
		return false;
	}

	const normalized = String(formattedDiscount).replace(/[^0-9]/g, "");

	return normalized !== "" && Number(normalized) > 0;
}

export function findSummaryDetailValue(summaryDetails, label) {
	const row = (summaryDetails ?? []).find((detail) => detail.label === label);

	return row?.value ?? null;
}

export function buildCheckoutTotalSavingsPresentation(summaryDetails) {
	const formattedSubtotal = findSummaryDetailValue(summaryDetails, "Subtotal");
	const formattedDiscount = findSummaryDetailValue(summaryDetails, "Descuento");

	if (!formattedSubtotal || !formattedDiscount) {
		return null;
	}

	const discountDigits = String(formattedDiscount).replace(/[^0-9]/g, "");

	if (discountDigits === "" || Number(discountDigits) <= 0) {
		return null;
	}

	const subtotalCents = parseFormattedPriceToCents(formattedSubtotal);
	const discountCents = parseFormattedPriceToCents(formattedDiscount);
	const percent = computeDiscountPercent(subtotalCents, subtotalCents - discountCents);

	if (discountCents <= 0) {
		return null;
	}

	const formattedDiscountAmount = String(formattedDiscount).startsWith("-")
		? formattedDiscount
		: `-${String(formattedDiscount).replace(/^-/, "")}`;

	return {
		formattedDiscount: formattedDiscountAmount.replace(/^-/, ""),
		percent: percent && percent > 0 ? percent : null,
	};
}

export function isLaboratoryCheckoutSummaryItem(item) {
	return item?.showDefaultImage === false;
}
