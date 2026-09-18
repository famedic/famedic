import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
	createCheckoutAttemptTracker,
	formatStudyCountLabel,
	hasPreferredStoreSelection,
	isSelectorInitiallyVisible,
	preferredStoreCardMode,
	preferredStoreSummaryPresentation,
	shouldLoadCompatibleStores,
	shouldPromptForPreferredStore,
} from "../../resources/js/lib/laboratoryCartPreferredStoreUi.js";

describe("laboratoryCartPreferredStoreUi", () => {
	it("formats study count labels", () => {
		assert.equal(formatStudyCountLabel(0), "0 estudios");
		assert.equal(formatStudyCountLabel(1), "1 estudio");
		assert.equal(formatStudyCountLabel(2), "2 estudios");
	});

	it("detects preferred store selection", () => {
		assert.equal(hasPreferredStoreSelection(null), false);
		assert.equal(hasPreferredStoreSelection({}), false);
		assert.equal(
			hasPreferredStoreSelection({ id: 1, name: "Anzures" }),
			true,
		);
	});

	it("uses Sucursal de preferencia card modes", () => {
		assert.equal(preferredStoreCardMode(null), "empty");
		assert.equal(
			preferredStoreCardMode({ id: 1, name: "Anzures" }),
			"selected",
		);
	});

	it("prompts for preferred store only when none is selected", () => {
		assert.equal(shouldPromptForPreferredStore(null), true);
		assert.equal(
			shouldPromptForPreferredStore({ id: 1, name: "Anzures" }),
			false,
		);
	});

	it("builds summary presentation for selected and empty states", () => {
		assert.deepEqual(preferredStoreSummaryPresentation(null), {
			title: "Sucursal",
			primary: "Sin seleccionar",
			secondary: "Selección opcional",
			hasSelection: false,
		});

		assert.deepEqual(
			preferredStoreSummaryPresentation({ id: 1, name: "Anzures" }),
			{
				title: "Sucursal",
				primary: "Anzures",
				secondary: "Preferencia del paciente",
				hasSelection: true,
			},
		);
	});

	it("keeps selector hidden until explicitly opened", () => {
		assert.equal(
			isSelectorInitiallyVisible({
				desktopExpanded: false,
				mobileSheetOpen: false,
			}),
			false,
		);
		assert.equal(
			isSelectorInitiallyVisible({
				desktopExpanded: true,
				mobileSheetOpen: false,
			}),
			true,
		);
		assert.equal(
			isSelectorInitiallyVisible({
				desktopExpanded: false,
				mobileSheetOpen: true,
			}),
			true,
		);
	});

	it("loads compatible stores lazily when selector opens", () => {
		assert.equal(
			shouldLoadCompatibleStores({
				selectorOpen: false,
				compatibleStoresLoaded: false,
			}),
			false,
		);
		assert.equal(
			shouldLoadCompatibleStores({
				selectorOpen: true,
				compatibleStoresLoaded: false,
			}),
			true,
		);
		assert.equal(
			shouldLoadCompatibleStores({
				selectorOpen: true,
				compatibleStoresLoaded: true,
			}),
			false,
		);
	});

	it("prevents duplicate checkout attempts within cooldown", () => {
		const tracker = createCheckoutAttemptTracker();

		assert.equal(tracker.shouldProceed(1000), true);
		assert.equal(tracker.shouldProceed(1000), false);

		tracker.reset();
		assert.equal(tracker.shouldProceed(1000), true);
	});
});
