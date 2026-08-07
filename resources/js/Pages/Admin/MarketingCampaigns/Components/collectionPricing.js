export function formatCents(cents) {
	if (cents == null || cents === "") {
		return null;
	}

	const amount = Number(cents) / 100;

	if (!Number.isFinite(amount)) {
		return null;
	}

	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(amount);
}

export function computeCollectionPricing(items = []) {
	let famedicTotal = 0;
	let publicTotal = 0;
	let pricedCount = 0;

	for (const item of items) {
		const hasFamedic =
			item.famedic_price_cents != null && item.famedic_price_cents !== "";
		const hasPublic =
			item.public_price_cents != null && item.public_price_cents !== "";

		if (!hasFamedic || !hasPublic) {
			continue;
		}

		famedicTotal += Number(item.famedic_price_cents);
		publicTotal += Number(item.public_price_cents);
		pricedCount += 1;
	}

	const reliable = items.length > 0 && pricedCount === items.length;

	return {
		reliable,
		famedicTotal,
		publicTotal,
		savings: publicTotal - famedicTotal,
		pricedCount,
	};
}
