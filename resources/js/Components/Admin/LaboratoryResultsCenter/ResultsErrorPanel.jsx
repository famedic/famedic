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

export default function ResultsErrorPanel({ errors = [] }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex items-center justify-between gap-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-white">Errores</h2>
				<Badge color={errors.length ? "red" : "emerald"}>{errors.length ? `${errors.length} error(es)` : "Sin errores"}</Badge>
			</div>
			{errors.length ? (
				<Table dense wrap className="mt-3">
					<TableHead>
						<TableRow>
							<TableHeader>Etapa</TableHeader>
							<TableHeader>Mensaje</TableHeader>
							<TableHeader>Fecha</TableHeader>
							<TableHeader>Attempts</TableHeader>
							<TableHeader>Event</TableHeader>
							<TableHeader>Execution</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{errors.map((error, index) => (
							<TableRow key={`${error.stage}-${index}`}>
								<TableCell>{error.stage}</TableCell>
								<TableCell>{error.message}</TableCell>
								<TableCell>{formatDateTime(error.date)}</TableCell>
								<TableCell>{error.attempts ?? "—"}</TableCell>
								<TableCell>{error.event_type || "—"}</TableCell>
								<TableCell>{error.execution_id || "—"}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			) : (
				<p className="mt-3 text-sm text-zinc-500">No hay errores operativos registrados para esta orden.</p>
			)}
		</div>
	);
}
