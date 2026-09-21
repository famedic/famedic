import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { formatDateTime } from "./status";

function stageColor(status) {
	if (["published", "ok", "ready", "available"].includes(status)) return "emerald";
	if (["issues", "manual_review", "partial"].includes(status)) return "amber";
	if (["failed", "error"].includes(status)) return "red";
	if (["missing", "not_started", "not_published", "not_requested"].includes(status)) return "zinc";
	return "sky";
}

export default function ResultsPipelineTimeline({ pipeline = [] }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<h2 className="text-sm font-semibold text-zinc-900 dark:text-white">Pipeline</h2>
			<div className="mt-4 space-y-3">
				{pipeline.map((stage, index) => (
					<div key={`${stage.stage}-${index}`} className="grid gap-3 border-l-2 border-zinc-200 pl-4 md:grid-cols-[220px_1fr] dark:border-zinc-700">
						<div>
							<div className="font-medium">{stage.stage}</div>
							<div className="mt-1"><Badge color={stageColor(stage.status)}>{stage.status || "—"}</Badge></div>
						</div>
						<div className="grid gap-2 text-sm text-zinc-600 md:grid-cols-3 dark:text-zinc-400">
							<Text>Fecha: {formatDateTime(stage.date)}</Text>
							{stage.method && <Text>Método: {stage.method}</Text>}
							{stage.observations !== undefined && <Text>Observaciones: {stage.observations ?? "—"}</Text>}
							{stage.attempts !== undefined && <Text>Attempts: {stage.attempts ?? "—"}</Text>}
							{stage.event_type && <Text>Event: {stage.event_type}</Text>}
							{stage.execution_id && <Text>Execution ID: {stage.execution_id}</Text>}
						</div>
						{stage.errors?.length ? (
							<ul className="md:col-start-2 list-disc pl-5 text-xs text-red-600 dark:text-red-400">
								{stage.errors.map((error) => <li key={error}>{error}</li>)}
							</ul>
						) : null}
					</div>
				))}
			</div>
		</div>
	);
}
