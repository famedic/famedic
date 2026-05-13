import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { useForm } from "@inertiajs/react";
import { useState } from "react";

function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

function statusBadgeColor(status) {
	if (status === "completed" || status === "approved") return "emerald";
	if (status === "pending") return "amber";
	if (status === "rejected" || status === "failed") return "red";
	return "zinc";
}

function actorDisplayName(row) {
	const user = row.actor_user ?? row.actorUser ?? null;
	if (!user) return row.actor_user_id ? `Usuario #${row.actor_user_id}` : "-";
	if (user.full_name) return user.full_name;
	const parts = [user.name, user.paternal_lastname, user.maternal_lastname].filter(
		Boolean,
	);
	return parts.join(" ").trim() || user.email || `Usuario #${row.actor_user_id}`;
}

export default function CouponLogs({ logs, filters }) {
	const { data, setData, get, processing } = useForm({
		type: filters?.type ?? "",
		user_id: filters?.user_id ?? "",
		date_from: filters?.date_from ?? "",
		date_to: filters?.date_to ?? "",
	});
	const [expandedRows, setExpandedRows] = useState({});

	const submit = (e) => {
		e.preventDefault();
		get(route("admin.coupons.logs"));
	};

	const toggleRow = (id) => {
		setExpandedRows((prev) => ({ ...prev, [id]: !prev[id] }));
	};

	return (
		<AdminLayout title="Auditoria cupones">
			<Heading>Auditoria de cupones</Heading>
			<form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-5">
				<Field>
					<Label>Tipo</Label>
					<Input
						placeholder="settings, assignment..."
						value={data.type}
						onChange={(e) => setData("type", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Usuario ID</Label>
					<Input
						placeholder="ID de admin"
						value={data.user_id}
						onChange={(e) => setData("user_id", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Desde</Label>
					<Input
						type="date"
						value={data.date_from}
						onChange={(e) => setData("date_from", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Hasta</Label>
					<Input
						type="date"
						value={data.date_to}
						onChange={(e) => setData("date_to", e.target.value)}
					/>
				</Field>
				<div className="flex items-end">
					<Button type="submit" disabled={processing}>
						Filtrar
					</Button>
				</div>
			</form>

			<div className="mt-6">
				<PaginatedTable paginatedData={logs}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Accion</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Detalle</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{(logs?.data ?? []).length === 0 ? (
								<TableRow>
									<TableCell colSpan={6} className="text-zinc-500 dark:text-zinc-400">
										No hay registros para los filtros actuales.
									</TableCell>
								</TableRow>
							) : (
								(logs?.data ?? []).map((row) => (
									<TableRow key={row.id}>
										<TableCell className="whitespace-nowrap text-zinc-700 dark:text-zinc-300">
											{formatShortDateTime(row.created_at)}
										</TableCell>
										<TableCell className="text-zinc-900 dark:text-zinc-100">
											{row.type || "—"}
										</TableCell>
										<TableCell className="text-zinc-900 dark:text-zinc-100">
											{row.action || "—"}
										</TableCell>
										<TableCell>
											<Badge color={statusBadgeColor(row.status)}>{row.status || "—"}</Badge>
										</TableCell>
										<TableCell className="text-zinc-700 dark:text-zinc-300">
											<div className="text-zinc-900 dark:text-zinc-100">
												{actorDisplayName(row)}
											</div>
											<div className="text-xs text-zinc-500 dark:text-zinc-400">
												ID: {row.actor_user_id ?? "-"}
											</div>
										</TableCell>
										<TableCell>
											{expandedRows[row.id] ? (
												<pre className="max-w-xl overflow-auto whitespace-pre-wrap rounded-lg bg-zinc-100 p-2 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
													{JSON.stringify(row.context ?? {}, null, 2)}
												</pre>
											) : (
												<span className="text-xs text-zinc-500 dark:text-zinc-400">
													Detalle oculto
												</span>
											)}
											<Button
												type="button"
												plain
												className="mt-1 text-xs"
												onClick={() => toggleRow(row.id)}
											>
												{expandedRows[row.id] ? "Ocultar" : "Ver detalle"}
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
