import { Link } from "@inertiajs/react";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { compactNumber, formatDateTime, statusMeta } from "./status";

function StatusBadge({ status }) {
	const meta = statusMeta(status);
	return <Badge color={meta.color}>{meta.label}</Badge>;
}

function AiCounts({ ai }) {
	if (!ai?.total) return <span className="text-zinc-400">No solicitada</span>;

	return (
		<div className="flex flex-wrap gap-1">
			{Object.entries(ai.by_status || {}).map(([status, count]) =>
				count ? (
					<Badge key={status} color={status === "ready" ? "emerald" : status === "failed" || status === "invalid" ? "red" : "amber"}>
						{status}: {count}
					</Badge>
				) : null,
			)}
		</div>
	);
}

export default function ResultsCenterTable({ purchases }) {
	if (!purchases) return null;

	return (
		<PaginatedTable paginatedData={purchases}>
			<Table dense striped wrap>
				<TableHead>
					<TableRow>
						<TableHeader>Compra</TableHeader>
						<TableHeader>Fecha</TableHeader>
						<TableHeader>Estado</TableHeader>
						<TableHeader>PDF</TableHeader>
						<TableHeader>Extracción</TableHeader>
						<TableHeader>Obs.</TableHeader>
						<TableHeader>Validación</TableHeader>
						<TableHeader>Publicación</TableHeader>
						<TableHeader>Vision</TableHeader>
						<TableHeader>AI Explanation</TableHeader>
						<TableHeader>Último evento</TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{purchases.data.map((row) => (
						<TableRow key={row.id}>
							<TableCell>
								<Link className="font-medium text-famedic-dark hover:underline dark:text-famedic-lime" href={row.href}>
									#{row.id}
								</Link>
								<div className="text-xs text-zinc-500">{row.folio || "Sin folio"}</div>
							</TableCell>
							<TableCell>{formatDateTime(row.created_at)}</TableCell>
							<TableCell><StatusBadge status={row.status} /></TableCell>
							<TableCell>
								<div>{row.pdf?.has_pdf ? "Disponible" : "Sin PDF"}</div>
								<div className="text-xs text-zinc-500">v{row.pdf?.version_id || "—"} · {row.pdf?.classification || "—"}</div>
							</TableCell>
							<TableCell>
								<div>{row.extraction?.status || "—"}</div>
								<div className="text-xs text-zinc-500">{row.extraction?.method || "—"}</div>
							</TableCell>
							<TableCell>{compactNumber(row.observations_count)}</TableCell>
							<TableCell>
								{row.validation?.has_errors ? (
									<Badge color="red">{row.validation.error_count} error(es)</Badge>
								) : (
									<Badge color="emerald">OK</Badge>
								)}
							</TableCell>
							<TableCell>
								<div>{row.publication?.status || "—"}</div>
								<div className="text-xs text-zinc-500">{row.publication?.is_published ? formatDateTime(row.publication.published_at) : "No publicado"}</div>
							</TableCell>
							<TableCell>
								<div>{row.vision?.status || "none"}</div>
								<div className="text-xs text-zinc-500">{row.vision?.observations_count || 0} obs.</div>
							</TableCell>
							<TableCell><AiCounts ai={row.ai_explanation} /></TableCell>
							<TableCell>
								<div>{row.last_event?.type || "—"}</div>
								<div className="text-xs text-zinc-500">{formatDateTime(row.last_event?.created_at || row.updated_at)}</div>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</PaginatedTable>
	);
}
