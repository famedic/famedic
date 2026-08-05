import TechnicalDetailsDrawer from "@/Components/Admin/ClinicalInterpreter/AiReview/TechnicalDetailsDrawer";

/**
 * Support-only drawer. Neutral legend: "Solo soporte técnico".
 * Wired to TechnicalDetailsDrawer (FASE 6).
 */
export default function AiDetailsDrawer({
	open,
	onClose,
	interpretPayload = null,
}) {
	return (
		<TechnicalDetailsDrawer
			open={open}
			onClose={onClose}
			interpretPayload={interpretPayload}
		/>
	);
}
