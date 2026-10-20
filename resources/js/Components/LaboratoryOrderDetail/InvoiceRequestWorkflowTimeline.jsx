import clsx from "clsx";
import { Text } from "@/Components/Catalyst/text";

function TimelineStep({ state, label }) {
	const icon =
		state === "complete" ? "✓" : state === "current" ? "⏳" : "○";

	return (
		<div className="flex items-start gap-2">
			<span
				className={clsx(
					"mt-0.5 inline-flex size-5 shrink-0 items-center justify-center text-xs font-semibold",
					state === "complete" && "text-emerald-600 dark:text-emerald-400",
					state === "current" && "text-blue-600 dark:text-blue-400",
					state === "pending" && "text-zinc-400 dark:text-zinc-500",
				)}
				aria-hidden
			>
				{icon}
			</span>
			<Text
				className={clsx(
					"text-sm",
					state === "complete" && "text-zinc-800 dark:text-zinc-200",
					state === "current" && "font-medium text-blue-800 dark:text-blue-200",
					state === "pending" && "text-zinc-500 dark:text-zinc-400",
				)}
			>
				{label}
			</Text>
		</div>
	);
}

export default function InvoiceRequestWorkflowTimeline({
	invoiceRequest,
	hasInvoice = false,
}) {
	if (!invoiceRequest) {
		return null;
	}

	const workflowStatus = invoiceRequest.workflow_status;
	const activatedBy = invoiceRequest.activated_by;
	const isAwaiting = workflowStatus === "awaiting_sample_collection";
	const isSubmitted =
		workflowStatus === "submitted_to_billing" || (!workflowStatus && !isAwaiting);
	const activatedByResult = activatedBy === "result_available";

	let steps;

	if (hasInvoice) {
		steps = [
			{ state: "complete", label: "Solicitud registrada" },
			{
				state: "complete",
				label: activatedByResult ? "Resultado disponible" : "Estudio completado",
			},
			{ state: "complete", label: "Enviada a facturación" },
			{ state: "complete", label: "Factura disponible" },
		];
	} else if (isAwaiting) {
		steps = [
			{ state: "complete", label: "Solicitud registrada" },
			{ state: "current", label: "Esperando toma / resultado" },
			{ state: "pending", label: "Enviada a facturación" },
			{ state: "pending", label: "Factura disponible" },
		];
	} else if (isSubmitted) {
		steps = activatedByResult
			? [
					{ state: "complete", label: "Solicitud registrada" },
					{ state: "complete", label: "Resultado disponible" },
					{ state: "complete", label: "Enviada a facturación" },
					{ state: "current", label: "Factura disponible" },
				]
			: [
					{ state: "complete", label: "Solicitud registrada" },
					{ state: "complete", label: "Estudio completado" },
					{ state: "complete", label: "Enviada a facturación" },
					{ state: "current", label: "Factura disponible" },
				];
	} else {
		return null;
	}

	return (
		<div
			className="space-y-2 rounded-lg border border-zinc-200/80 bg-white/60 p-3 dark:border-zinc-700 dark:bg-zinc-900/40"
			aria-label="Progreso de solicitud de factura"
		>
			{steps.map((step) => (
				<TimelineStep key={step.label} {...step} />
			))}
		</div>
	);
}
