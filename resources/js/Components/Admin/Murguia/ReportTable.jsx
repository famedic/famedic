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

const statusColor = {
	active: "green",
	inactive: "zinc",
	expired: "red",
	no_subscription: "zinc",
	synced: "green",
	pending: "amber",
	error: "red",
	no_log: "zinc",
};

const statusLabel = {
	active: "Activo",
	inactive: "Inactivo",
	expired: "Vencido",
	no_subscription: "Sin suscripción",
	synced: "Sincronizado",
	pending: "Pendiente",
	error: "Error",
	no_log: "Sin log",
};

const subLabel = {
	trial: "Trial",
	regular: "Regular",
	institutional: "Institucional",
	family_member: "Miembro familiar",
	none: "Ninguna",
};

export default function ReportTable({ rows }) {
	return (
		<PaginatedTable paginatedData={rows}>
			<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>ID</TableHeader>
							<TableHeader>Nombre</TableHeader>
							<TableHeader>Email</TableHeader>
							<TableHeader>noCredito</TableHeader>
							<TableHeader>Cuenta</TableHeader>
							<TableHeader>Suscripción</TableHeader>
							<TableHeader>Estado local</TableHeader>
							<TableHeader>Sync Murguía</TableHeader>
							<TableHeader>Expiración</TableHeader>
							<TableHeader>Origen</TableHeader>
							<TableHeader>Observaciones</TableHeader>
							<TableHeader />
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.data.length === 0 ? (
							<TableRow>
								<TableCell colSpan={12} className="text-center text-zinc-500">
									Sin resultados para los filtros seleccionados.
								</TableCell>
							</TableRow>
						) : (
							rows.data.map((row) => (
								<TableRow key={row.customer_id}>
									<TableCell>{row.customer_id}</TableCell>
									<TableCell className="min-w-[10rem]">{row.full_name || "—"}</TableCell>
									<TableCell className="min-w-[10rem]">{row.email || "—"}</TableCell>
									<TableCell>{row.medical_attention_identifier || "—"}</TableCell>
									<TableCell>{row.account_type}</TableCell>
									<TableCell>{subLabel[row.subscription_type] || row.subscription_type}</TableCell>
									<TableCell>
										<Badge color={statusColor[row.local_status] || "zinc"}>
											{statusLabel[row.local_status] || row.local_status}
										</Badge>
									</TableCell>
									<TableCell>
										<Badge color={statusColor[row.murguia_sync_status] || "zinc"}>
											{statusLabel[row.murguia_sync_status] || row.murguia_sync_status}
										</Badge>
									</TableCell>
									<TableCell>
										<div className="text-sm">
											{row.subscription_end_date || "—"}
										</div>
										{row.days_remaining_or_overdue ? (
											<div className="text-xs text-zinc-500">
												{row.days_remaining_or_overdue}
											</div>
										) : null}
									</TableCell>
									<TableCell className="min-w-[8rem]">{row.origin}</TableCell>
									<TableCell className="max-w-xs truncate text-xs text-zinc-600 dark:text-zinc-400">
										{row.reconciliation_notes || "—"}
									</TableCell>
									<TableCell>
										<Link
											href={route("admin.murguia-monitor.show", row.customer_id)}
											className="text-sm text-famedic-dark hover:underline dark:text-famedic-lime"
										>
											Ver
										</Link>
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
