import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

export default function OrdersIndex({ orders }) {
	const rows = orders?.data || [];
	const paginated =
		orders?.links
			? orders
			: {
					data: rows,
					links: [],
					prev_page_url: null,
					next_page_url: null,
				};

	return (
		<AdminLayout title="Clinical Orders">
			<div className="space-y-6 pb-8">
				<nav className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]">
					<Link
						href={route("admin.clinical-interpreter.index")}
						className="font-medium text-zinc-400 hover:text-famedic-light"
					>
						AI Clinical Interpreter
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Clinical Orders
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-3">
					<div className="space-y-1">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Clinical Orders</Heading>
							<Badge color="famedic">Listado</Badge>
						</div>
						<Text className="text-sm text-zinc-500">
							Órdenes clínicas de laboratorio generadas tras validación humana.
						</Text>
					</div>
					<Button href={route("admin.clinical-interpreter.matching")}>
						Nueva interpretación
					</Button>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					{rows.length === 0 ? (
						<p className="py-8 text-center text-sm text-zinc-400">
							No hay Clinical Orders. Completa una interpretación validada.
						</p>
					) : (
						<PaginatedTable paginatedData={paginated}>
							<Table dense>
								<TableHead>
									<TableRow>
										<TableHeader>ID</TableHeader>
										<TableHeader>UUID</TableHeader>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>Fecha</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Total</TableHeader>
										<TableHeader>Estudios</TableHeader>
										<TableHeader>Operador</TableHeader>
										<TableHeader>Acciones</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{rows.map((order) => (
										<TableRow key={order.uuid || order.id}>
											<TableCell className="font-medium">#{order.id}</TableCell>
											<TableCell className="max-w-[8rem] truncate text-xs text-zinc-500">
												{order.uuid}
											</TableCell>
											<TableCell>{order.patient_name || "—"}</TableCell>
											<TableCell className="text-xs text-zinc-500">
												{order.created_at
													? new Date(order.created_at).toLocaleString("es-MX")
													: "—"}
											</TableCell>
											<TableCell>
												<Badge color="sky" className="!text-[10px]">
													{order.status_label}
												</Badge>
											</TableCell>
											<TableCell>{order.total}</TableCell>
											<TableCell>{order.studies_count}</TableCell>
											<TableCell className="text-xs">
												{order.operator?.name || "—"}
											</TableCell>
											<TableCell>
												<Button
													outline
													href={order.show_url}
													className="!py-1 text-xs"
												>
													Abrir
												</Button>
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</PaginatedTable>
					)}
				</div>
			</div>
		</AdminLayout>
	);
}
