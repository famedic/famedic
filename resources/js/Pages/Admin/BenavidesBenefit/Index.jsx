import { useEffect, useMemo } from "react";
import { useForm } from "@inertiajs/react";
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
import SearchInput from "@/Components/Admin/SearchInput";
import PaginatedTable from "@/Components/Admin/PaginatedTable";

function formatDate(value) {
	if (!value) return "—";
	return new Intl.DateTimeFormat("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	}).format(new Date(value));
}

function MetricCard({ label, value, help }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<div className="mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">{value}</div>
			{help ? <Text className="mt-1 text-xs text-zinc-500">{help}</Text> : null}
		</div>
	);
}

export default function Index({ metrics, codes, filters }) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		status: filters.status || "all",
	});

	useEffect(() => {
		setData({
			search: filters.search || "",
			status: filters.status || "all",
		});
	}, [filters.search, filters.status]);

	const hasChanges = useMemo(
		() => data.search !== (filters.search || "") || data.status !== (filters.status || "all"),
		[data, filters],
	);

	const applyFilters = (event) => {
		event.preventDefault();
		if (!processing) {
			get(route("admin.benavides-benefit.index"), { preserveState: true });
		}
	};

	return (
		<AdminLayout title="Beneficio Farmacias Benavides">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Beneficio Farmacias Benavides</Heading>
						<Text className="mt-2 max-w-3xl">
							Administra el inventario de códigos asignables a usuarios FAMEDIC.
						</Text>
					</div>
					<Button href={route("admin.benavides-benefit.import")}>Importar códigos</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-4">
					<MetricCard label="Total" value={metrics.total.toLocaleString("es-MX")} />
					<MetricCard label="Disponibles" value={metrics.available.toLocaleString("es-MX")} />
					<MetricCard label="Asignados" value={metrics.assigned.toLocaleString("es-MX")} />
					<MetricCard label="Uso" value={`${metrics.usagePercent}%`} />
				</div>

				{metrics.latestImport ? (
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
						<Text className="font-semibold text-zinc-950 dark:text-white">Última importación</Text>
						<Text className="mt-1">
							{metrics.latestImport.original_filename || "Archivo sin nombre"} ·{" "}
							{Number(metrics.latestImport.imported_rows || 0).toLocaleString("es-MX")} importados ·{" "}
							{Number(metrics.latestImport.rejected_rows || 0).toLocaleString("es-MX")} rechazados ·{" "}
							{metrics.latestImport.confirmed_by || "Sin confirmador"} ·{" "}
							{formatDate(metrics.latestImport.created_at)}
						</Text>
					</div>
				) : null}

				<form onSubmit={applyFilters} className="flex flex-wrap items-center gap-3">
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
						placeholder="Buscar código, usuario, email o ID..."
					/>
					<select
						value={data.status}
						onChange={(event) => setData("status", event.target.value)}
						className="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
					>
						<option value="all">Todos</option>
						<option value="available">Disponibles</option>
						<option value="assigned">Asignados</option>
					</select>
					<Button type="submit" disabled={processing || !hasChanges}>
						Actualizar resultados
					</Button>
				</form>

				<PaginatedTable paginatedData={codes}>
					<Table dense striped>
						<TableHead>
							<TableRow>
								<TableHeader>Código</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Email</TableHeader>
								<TableHeader>Asignado</TableHeader>
								<TableHeader>Lote</TableHeader>
								<TableHeader>Creado</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{codes.data.map((item) => (
								<TableRow key={item.id}>
									<TableCell className="font-mono">{item.code}</TableCell>
									<TableCell>
										<Badge color={item.status === "assigned" ? "lime" : "zinc"}>
											{item.status === "assigned" ? "Asignado" : "Disponible"}
										</Badge>
									</TableCell>
									<TableCell>{item.user ? `${item.user.name} (#${item.user.id})` : "—"}</TableCell>
									<TableCell>{item.user?.email || "—"}</TableCell>
									<TableCell>{formatDate(item.assigned_at)}</TableCell>
									<TableCell>{item.import_batch?.filename || "—"}</TableCell>
									<TableCell>{formatDate(item.created_at)}</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}
