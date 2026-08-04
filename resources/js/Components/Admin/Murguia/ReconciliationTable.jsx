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

const issueLabels = {
	matched_ok: "OK",
	provider_only: "Solo proveedor",
	local_only: "Solo local",
	provider_active_local_expired: "Activo prov. / vencido local",
	local_active_provider_inactive: "Activo local / inact. prov.",
	duplicate_credito_in_file: "noCredito dup.",
	duplicate_email_in_file: "Email dup.",
	name_mismatch: "Nombre",
	membership_type_mismatch: "Membresía",
};

const issueColors = {
	matched_ok: "green",
	provider_only: "amber",
	local_only: "amber",
	provider_active_local_expired: "red",
	local_active_provider_inactive: "red",
	duplicate_credito_in_file: "orange",
	duplicate_email_in_file: "orange",
	name_mismatch: "purple",
	membership_type_mismatch: "purple",
};

export default function ReconciliationTable({ issues }) {
	return (
		<PaginatedTable paginatedData={issues}>
			<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>Tipo</TableHeader>
							<TableHeader>Proveedor</TableHeader>
							<TableHeader>Local</TableHeader>
							<TableHeader>Observación</TableHeader>
							<TableHeader />
						</TableRow>
					</TableHead>
					<TableBody>
						{issues.data.length === 0 ? (
							<TableRow>
								<TableCell colSpan={5} className="text-center text-zinc-500">
									Sin diferencias para el filtro seleccionado.
								</TableCell>
							</TableRow>
						) : (
							issues.data.map((issue, index) => (
								<TableRow key={`${issue.issue_type}-${index}`}>
									<TableCell>
										<Badge color={issueColors[issue.issue_type] || "zinc"}>
											{issueLabels[issue.issue_type] || issue.issue_type}
										</Badge>
									</TableCell>
									<TableCell className="min-w-[14rem] text-xs">
										{issue.provider ? (
											<div className="space-y-0.5">
												{issue.provider.row_number ? (
													<div>Fila {issue.provider.row_number}</div>
												) : null}
												<div>{issue.provider.full_name || "—"}</div>
												<div className="text-zinc-500">
													{issue.provider.email || "—"}
												</div>
												<div className="font-mono text-zinc-600 dark:text-zinc-400">
													{issue.provider.medical_attention_identifier || "—"}
												</div>
												{issue.provider.provider_status ? (
													<div>Estatus: {issue.provider.provider_status}</div>
												) : null}
											</div>
										) : (
											"—"
										)}
									</TableCell>
									<TableCell className="min-w-[14rem] text-xs">
										{issue.local ? (
											<div className="space-y-0.5">
												<div>#{issue.local.customer_id}</div>
												<div>{issue.local.full_name || "—"}</div>
												<div className="text-zinc-500">
													{issue.local.email || "—"}
												</div>
												<div className="font-mono text-zinc-600 dark:text-zinc-400">
													{issue.local.medical_attention_identifier || "—"}
												</div>
												<div>
													{issue.local.local_status} ·{" "}
													{issue.local.subscription_type}
												</div>
											</div>
										) : (
											"—"
										)}
									</TableCell>
									<TableCell className="max-w-md text-xs text-zinc-600 dark:text-zinc-400">
										{issue.observation}
									</TableCell>
									<TableCell>
										{issue.local?.customer_id ? (
											<Link
												href={route(
													"admin.murguia-monitor.show",
													issue.local.customer_id,
												)}
												className="text-sm text-famedic-dark hover:underline dark:text-famedic-lime"
											>
												Ver
											</Link>
										) : null}
									</TableCell>
								</TableRow>
							))
						)}
					</TableBody>
				</Table>
			</div>
		</PaginatedTable>
	);
}
