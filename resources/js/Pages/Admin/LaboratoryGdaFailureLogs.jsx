import { Link, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import SearchInput from "@/Components/Admin/SearchInput";
import { Select } from "@/Components/Catalyst/select";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

function operationBadgeColor(operation) {
	switch (operation) {
		case "checkout":
			return "blue";
		case "admin_recover":
			return "amber";
		case "admin_replace":
			return "violet";
		default:
			return "zinc";
	}
}

export default function LaboratoryGdaFailureLogs({
	logs,
	filters,
	operations,
	brands,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search ?? "",
		operation: filters.operation ?? "",
		brand: filters.brand ?? "",
	});

	const submitFilters = (event) => {
		event.preventDefault();
		get(route("admin.laboratory-gda-failure-logs.index"), {
			preserveState: true,
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="Errores GDA">
			<div className="space-y-6">
				<div>
					<Heading>Errores GDA</Heading>
					<Text className="mt-1">
						Trazabilidad de fallos al crear o recuperar pedidos de laboratorio en
						GDA.
					</Text>
				</div>

				<form
					onSubmit={submitFilters}
					className="grid gap-4 md:grid-cols-[1fr_180px_180px_auto]"
				>
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
						placeholder="Buscar por pedido, cliente, detalle GDA…"
					/>
					<Select
						value={data.operation}
						onChange={(event) => setData("operation", event.target.value)}
					>
						<option value="">Todas las operaciones</option>
						{operations.map((operation) => (
							<option key={operation.value} value={operation.value}>
								{operation.label}
							</option>
						))}
					</Select>
					<Select
						value={data.brand}
						onChange={(event) => setData("brand", event.target.value)}
					>
						<option value="">Todas las marcas</option>
						{Object.entries(brands || {}).map(([value, brand]) => (
							<option key={value} value={value}>
								{brand.name}
							</option>
						))}
					</Select>
					<Button type="submit" disabled={processing}>
						Filtrar
					</Button>
				</form>

				<PaginatedTable paginatedData={logs}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Operación</TableHeader>
								<TableHeader>Pedido</TableHeader>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Detalle GDA</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{logs.data.length === 0 ? (
								<TableRow>
									<TableCell colSpan={6}>
										<Text>No hay errores registrados con estos filtros.</Text>
									</TableCell>
								</TableRow>
							) : (
								logs.data.map((log) => (
									<TableRow key={log.id}>
										<TableCell>{log.created_at}</TableCell>
										<TableCell>
											<Badge color={operationBadgeColor(log.operation)}>
												{log.operation_label}
											</Badge>
										</TableCell>
										<TableCell>
											<div className="space-y-1 text-sm">
												{log.laboratory_purchase_id && (
													<div>
														<Link
															href={route(
																"admin.laboratory-purchases.show",
																log.laboratory_purchase_id,
															)}
															className="font-medium underline"
														>
															#{log.laboratory_purchase_id}
														</Link>
													</div>
												)}
												{log.source_laboratory_purchase_id &&
													log.source_laboratory_purchase_id !==
														log.laboratory_purchase_id && (
														<div className="text-zinc-500">
															Origen #
															{log.source_laboratory_purchase_id}
														</div>
													)}
												{log.requisition_value && (
													<div className="text-zinc-500">
														Req. GDA: {log.requisition_value}
													</div>
												)}
											</div>
										</TableCell>
										<TableCell>
											<div className="text-sm">
												<div>{log.customer_name ?? "—"}</div>
												{log.customer_email && (
													<div className="text-zinc-500">
														{log.customer_email}
													</div>
												)}
											</div>
										</TableCell>
										<TableCell>
											<div className="max-w-md text-sm">
												{log.gda_description && (
													<div className="font-medium text-red-700 dark:text-red-300">
														{log.gda_description}
													</div>
												)}
												<div className="text-zinc-600 dark:text-zinc-300">
													{log.message}
												</div>
											</div>
										</TableCell>
										<TableCell className="text-right">
											<Button
												href={route(
													"admin.laboratory-gda-failure-logs.show",
													log.id,
												)}
												outline
											>
												Ver detalle
											</Button>
										</TableCell>
									</TableRow>
								))
							)}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}
