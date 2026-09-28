import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import InvoiceRequestWorkflowTimeline from "@/Components/LaboratoryOrderDetail/InvoiceRequestWorkflowTimeline";

export default function AdminInvoiceRequestWorkflowPanel({ workflow }) {
	if (!workflow) {
		return null;
	}

	const isAwaiting = workflow.workflow_status === "awaiting_sample_collection";
	const isSubmitted = workflow.workflow_status === "submitted_to_billing";

	return (
		<div className="rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-900/50">
			<Subheading level={3}>Solicitud de factura</Subheading>
			<div className="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
				<InvoiceRequestWorkflowTimeline
					invoiceRequest={{
						workflow_status: workflow.workflow_status,
						activated_by: workflow.activated_by,
					}}
				/>
			</div>

			<div className="mt-4 space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
				{workflow.formatted_requested_at && (
					<Text>
						<Strong>Fecha de solicitud:</Strong> {workflow.formatted_requested_at}
					</Text>
				)}
				{isSubmitted && workflow.formatted_submitted_to_billing_at && (
					<Text>
						<Strong>Enviada:</Strong> {workflow.formatted_submitted_to_billing_at}
					</Text>
				)}
				{isAwaiting && workflow.formatted_sample_completed_at && (
					<Text>
						<Strong>Toma completada:</Strong> {workflow.formatted_sample_completed_at}
					</Text>
				)}
				{workflow.activated_by_label && (
					<Text>
						<Strong>{workflow.activated_by_label}</Strong>
					</Text>
				)}
			</div>

			{(workflow.status_logs || []).length > 0 && (
				<div className="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
					<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
						Historial
					</Text>
					<ul className="mt-2 space-y-3">
						{workflow.status_logs.map((log) => (
							<li key={log.id} className="text-sm text-zinc-600 dark:text-zinc-400">
								<p className="font-medium text-zinc-800 dark:text-zinc-200">
									{log.formatted_created_at}
								</p>
								<p>{log.event_label || log.trigger_label}</p>
								<p className="text-xs text-zinc-500 dark:text-zinc-500">
									{log.trigger_label}
								</p>
							</li>
						))}
					</ul>
				</div>
			)}
		</div>
	);
}
