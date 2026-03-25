import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
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

const entryLabel = {
	bulk: "Masivo (Excel)",
	single: "Individual",
};

export default function MurguiaLogs({ logs }) {
	return (
		<AdminLayout title="Murguía — logs">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Auditoría Murguía</Heading>
					<Link
						href={route("admin.murguia-monitor.index")}
						className="text-sm text-blue-600 hover:underline dark:text-blue-400"
					>
						← Monitor
					</Link>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<PaginatedTable paginatedData={logs}>
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Fecha</TableHeader>
									<TableHeader>Origen</TableHeader>
									<TableHeader>Admin</TableHeader>
									<TableHeader>Acción</TableHeader>
									<TableHeader>Estado</TableHeader>
									<TableHeader>Email</TableHeader>
									<TableHeader>noCredito</TableHeader>
									<TableHeader>Cliente</TableHeader>
									<TableHeader>Mensaje</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{logs.data.length === 0 ? (
									<TableRow>
										<TableCell colSpan={9}>
											<Text className="py-8 text-center text-zinc-500">Sin registros.</Text>
										</TableCell>
									</TableRow>
								) : (
									logs.data.map((log) => (
										<TableRow key={log.id}>
											<TableCell className="whitespace-nowrap text-sm">
												{new Date(log.created_at).toLocaleString("es-MX")}
											</TableCell>
											<TableCell>
												<Badge color={log.entry_type === "single" ? "blue" : "zinc"}>
													{entryLabel[log.entry_type] || log.entry_type}
												</Badge>
											</TableCell>
											<TableCell className="max-w-[140px] truncate text-sm">
												{log.admin_email || "—"}
											</TableCell>
											<TableCell>{log.action}</TableCell>
											<TableCell>{log.status}</TableCell>
											<TableCell>{log.email || "—"}</TableCell>
											<TableCell className="font-mono text-sm">
												{log.medical_attention_identifier || "—"}
											</TableCell>
											<TableCell>
												{log.customer_id ? (
													<Link
														href={route("admin.murguia-monitor.show", log.customer_id)}
														className="text-blue-600 hover:underline dark:text-blue-400"
													>
														#{log.customer_id}
													</Link>
												) : (
													"—"
												)}
											</TableCell>
											<TableCell className="max-w-md truncate text-sm">{log.message}</TableCell>
										</TableRow>
									))
								)}
							</TableBody>
						</Table>
					</PaginatedTable>
				</div>
			</div>
		</AdminLayout>
	);
}
