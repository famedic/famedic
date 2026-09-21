import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { formatDateTime } from "./status";

function statusColor(status) {
	if (status === "succeeded") return "emerald";
	if (status === "failed") return "red";
	if (status === "processing" || status === "queued") return "amber";
	return "zinc";
}

export default function AiExecutionSummary({ executions = [] }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<h2 className="text-sm font-semibold text-zinc-900 dark:text-white">AI observability</h2>
			{executions.length ? (
				<Table dense wrap className="mt-3">
					<TableHead>
						<TableRow>
							<TableHeader>ID</TableHeader>
							<TableHeader>Origen</TableHeader>
							<TableHeader>Status</TableHeader>
							<TableHeader>Duration</TableHeader>
							<TableHeader>Tokens</TableHeader>
							<TableHeader>Cost</TableHeader>
							<TableHeader>Error</TableHeader>
							<TableHeader>Fecha</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{executions.map((execution) => (
							<TableRow key={execution.id}>
								<TableCell>{execution.id}</TableCell>
								<TableCell>{execution.source} #{execution.source_id}</TableCell>
								<TableCell><Badge color={statusColor(execution.status)}>{execution.status}</Badge></TableCell>
								<TableCell>{execution.duration_ms ?? "—"} ms</TableCell>
								<TableCell>{execution.total_tokens ?? "—"}</TableCell>
								<TableCell>{execution.estimated_cost_usd ?? "—"}</TableCell>
								<TableCell>{execution.error || "—"}</TableCell>
								<TableCell>{formatDateTime(execution.created_at)}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			) : (
				<p className="mt-3 text-sm text-zinc-500">No hay ejecuciones AI asociadas a esta orden.</p>
			)}
		</div>
	);
}
