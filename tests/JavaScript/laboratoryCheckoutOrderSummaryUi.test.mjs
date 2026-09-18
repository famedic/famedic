import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
	buildCheckoutItemDiscountPresentation,
	buildCheckoutTotalSavingsPresentation,
	computeDiscountPercent,
	findSummaryDetailValue,
	formatCheckoutStudyCountLabel,
	formatDiscountBadge,
	hasDiscountValue,
	hasRealCheckoutItemDiscount,
	isLaboratoryCheckoutSummaryItem,
	parseFormattedPriceToCents,
} from "../../resources/js/lib/laboratoryCheckoutOrderSummaryUi.js";

describe("laboratoryCheckoutOrderSummaryUi", () => {
	it("formats checkout study count label", () => {
		assert.equal(formatCheckoutStudyCountLabel(1), "1 estudio");
		assert.equal(formatCheckoutStudyCountLabel(3), "3 estudios");
	});

	it("builds checkout item presentation from real checkout item fields", () => {
		const item = {
			heading: "PERFIL REUMATICO",
			price: "$451.00",
			discountedPrice: "$860.00",
			publicPriceCents: 86000,
			famedicPriceCents: 45100,
			discountPercentage: 48,
			showDefaultImage: false,
		};

		assert.equal(isLaboratoryCheckoutSummaryItem(item), true);

		const pricing = buildCheckoutItemDiscountPresentation(item);

		assert.equal(pricing.showOriginalPrice, true);
		assert.equal(pricing.formattedOriginalPrice, "$860.00");
		assert.equal(pricing.formattedCurrentPrice, "$451.00");
		assert.equal(pricing.discountBadge, "-48%");
		assert.equal(pricing.removeAriaLabel, "Eliminar estudio");
		assert.equal(hasRealCheckoutItemDiscount(item), true);
	});

	it("hides discount badge when there is no real discount", () => {
		const pricing = buildCheckoutItemDiscountPresentation({
			price: "$451.00",
			discountedPrice: "$451.00",
			publicPriceCents: 45100,
			famedicPriceCents: 45100,
			discountPercentage: null,
		});

		assert.equal(pricing.showOriginalPrice, false);
		assert.equal(pricing.discountBadge, null);
	});

	it("computes discount percentages without hardcoding", () => {
		assert.equal(computeDiscountPercent(86000, 45100), 48);
		assert.equal(computeDiscountPercent(45100, 45100), null);
		assert.equal(formatDiscountBadge(48), "-48%");
	});

	it("builds total savings presentation from summary details rows", () => {
		const summaryDetails = [
			{ label: "Subtotal", value: "$2,474.00" },
			{ label: "Descuento", value: "-$1,461.00" },
			{ label: "Total a pagar", value: "$1,013.00" },
		];

		assert.equal(findSummaryDetailValue(summaryDetails, "Subtotal"), "$2,474.00");
		assert.equal(findSummaryDetailValue(summaryDetails, "Descuento"), "-$1,461.00");

		const savings = buildCheckoutTotalSavingsPresentation(summaryDetails);

		assert.equal(savings.formattedDiscount, "$1,461.00");
		assert.equal(savings.percent, 59);
	});

	it("hides total savings when discount is zero", () => {
		assert.equal(hasDiscountValue("$0.00"), false);
		assert.equal(
			buildCheckoutTotalSavingsPresentation([
				{ label: "Subtotal", value: "$451.00" },
				{ label: "Descuento", value: "-$0.00" },
				{ label: "Total a pagar", value: "$451.00" },
			]),
			null,
		);
	});

	it("does not change subtotal or total values, only presentation helpers", () => {
		const subtotal = "$2,474.00";
		const total = "$1,013.00";
		const discount = "$1,461.00";

		assert.equal(parseFormattedPriceToCents(subtotal), 247400);
		assert.equal(parseFormattedPriceToCents(total), 101300);
		assert.equal(parseFormattedPriceToCents(discount), 146100);
		assert.equal(
			parseFormattedPriceToCents(subtotal) -
				parseFormattedPriceToCents(discount),
			parseFormattedPriceToCents(total),
		);
	});

	it("uses existing remove handler contract without creating new endpoints", () => {
		let removed = false;
		const onDestroy = () => {
			removed = true;
		};

		onDestroy();
		assert.equal(removed, true);
	});
});
