/**
 * AI Laboratory Interpreter — product principles (UX v2).
 * Respect across all implementation phases.
 *
 * 1. One decision per screen
 * 2. AI speaks first (summary before matching)
 * 3. Matching via simple cards + accordion
 * 4. Constant progress (3-stage persistent bar)
 * 5. Technical details only in "Detalles IA" drawer
 * 6. Laboratory Order = clinical dossier, not JSON/table-first
 * 7. Premium microinteractions (subtle, Stripe/Linear/Notion)
 *
 * Pharmacy architecture remains in services/adapters; UI is labs-only.
 */
export const PHARMACY_UI_ENABLED = false;

export const PRODUCT_SCOPE = {
	version: "1.0",
	focus: "laboratory",
	pharmacyUiEnabled: PHARMACY_UI_ENABLED,
	productName: "AI Laboratory Interpreter",
};

/** Visible wizard stages — never expose internal substeps in the stepper. */
export const WIZARD_STAGES = [
	{
		id: "interpret",
		number: 1,
		label: "Interpretar",
		description: "Sube la orden y confía en lo que detectó la IA",
	},
	{
		id: "validate",
		number: 2,
		label: "Validar",
		description: "Revisa cada estudio y confirma la orden",
	},
	{
		id: "finalize",
		number: 3,
		label: "Finalizar",
		description: "Resumen de la Orden — genera el expediente de laboratorio",
	},
];

export const STAGE_INDEX = {
	interpret: 0,
	validate: 1,
	finalize: 2,
};
