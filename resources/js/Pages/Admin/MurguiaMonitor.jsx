import { Link, router, useForm } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
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

const accountLabel = {
	odessa: "Odessa",
	regular: "Regular",
	familiar: "Familiar",
};

const subLabel = {
	trial: "Trial",
	regular: "Regular",
	institutional: "Institucional Odessa",
	family_member: "Miembro familiar",
	none: "Ninguna",
};

export default function MurguiaMonitor({
	customers,
	filters,
	stats,
	murguiaCheck,
	successMessage,
	errorMessage,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		account_type: filters.account_type || "",
		local_status: filters.local_status || "",
		subscription_type: filters.subscription_type || "",
		murguia_sync: filters.murguia_sync || "",
	});

	const [confirmState, setConfirmState] = useState(null);
	const [rowBusy, setRowBusy] = useState(null);

	const showUpdate = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.account_type || "") !== (filters.account_type || "") ||
			(data.local_status || "") !== (filters.local_status || "") ||
			(data.subscription_type || "") !== (filters.subscription_type || "") ||
			(data.murguia_sync || "") !== (filters.murguia_sync || ""),
		[data, filters],
	);

	const applyFilters = (e) => {
		e.preventDefault();
		if (!processing && showUpdate) {
			get(route("admin.murguia-monitor.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const postCheck = (customerId) => {
		setRowBusy(customerId);
		router.post(
			route("admin.murguia-monitor.check-status", customerId),
			{},
			{
				preserveScroll: true,
				onFinish: () => setRowBusy(null),
			},
		);
	};

	const runConfirmed = () => {
		if (!confirmState) return;
		const { id, action } = confirmState;
		setConfirmState(null);
		setRowBusy(id);
		const name =
			action === "activate" ? "admin.murguia.activate" : "admin.murguia.deactivate";
		router.post(route(name, id), {}, {
			preserveScroll: true,
			onFinish: () => setRowBusy(null),
		});
	};

	return (
		<AdminLayout title="Murguía — asegurados">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				{successMessage && (
					<div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
						{successMessage}
					</div>
				)}

				{errorMessage && (
					<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
						{errorMessage}
					</div>
				)}

				{murguiaCheck && (
					<div className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm dark:border-blue-800 dark:bg-blue-950/30">
						<p className="font-medium text-blue-900 dark:text-blue-200">
							Consulta Murguía — HTTP {murguiaCheck.http}
						</p>
						<pre className="mt-2 max-h-40 overflow-auto rounded bg-white/80 p-2 text-xs text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
							{JSON.stringify(murguiaCheck.body, null, 2)}
						</pre>
					</div>
				)}

				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Monitor de asegurados (Murguía)</Heading>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.murguia.upload")} outline>
							Carga Excel
						</Button>
						<Button href={route("admin.murguia.logs")} outline>
							Logs de auditoría
						</Button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm text-zinc-500">Activos (local)</Text>
						<p className="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
							{stats.total_local_active}
						</p>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm text-zinc-500">Inactivos (local)</Text>
						<p className="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
							{stats.total_local_inactive}
						</p>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm text-zinc-500">Suscripción vigente</Text>
						<p className="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
							{stats.total_subscription_active}
						</p>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm text-zinc-500">Sin compras laboratorio</Text>
						<p className="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
							{stats.total_no_lab_usage}
						</p>
					</div>
				</div>

				<form
					onSubmit={applyFilters}
					className="flex flex-wrap items-end gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="min-w-[200px] flex-1">
						<Text className="mb-1 text-xs text-zinc-500">Búsqueda</Text>
						<input
							type="search"
							value={data.search}
							onChange={(e) => setData("search", e.target.value)}
							placeholder="Nombre, email, no. crédito…"
							className="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
						/>
					</div>
					<div>
						<Text className="mb-1 text-xs text-zinc-500">Tipo cuenta</Text>
						<select
							value={data.account_type}
							onChange={(e) => setData("account_type", e.target.value)}
							className="rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
						>
							<option value="">Todos</option>
							<option value="odessa">Odessa</option>
							<option value="regular">Regular</option>
							<option value="familiar">Familiar</option>
						</select>
					</div>
					<div>
						<Text className="mb-1 text-xs text-zinc-500">Estado local</Text>
						<select
							value={data.local_status}
							onChange={(e) => setData("local_status", e.target.value)}
							className="rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
						>
							<option value="">Todos</option>
							<option value="active">Suscripción activa</option>
							<option value="inactive">Sin vigencia / vencida</option>
						</select>
					</div>
					<div>
						<Text className="mb-1 text-xs text-zinc-500">Tipo suscripción</Text>
						<select
							value={data.subscription_type}
							onChange={(e) => setData("subscription_type", e.target.value)}
							className="rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
						>
							<option value="">Todas</option>
							<option value="trial">Trial</option>
							<option value="regular">Regular</option>
							<option value="institutional">Institucional</option>
							<option value="family_member">Miembro familiar</option>
							<option value="none">Sin suscripción</option>
						</select>
					</div>
					<div>
						<Text className="mb-1 text-xs text-zinc-500">Sync Murguía</Text>
						<select
							value={data.murguia_sync}
							onChange={(e) => setData("murguia_sync", e.target.value)}
							className="rounded-md border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
						>
							<option value="">Todos</option>
							<option value="never">Nunca sincronizado</option>
							<option value="synced">Alguna vez sincronizado</option>
						</select>
					</div>
					<Button type="submit" disabled={processing || !showUpdate}>
						Aplicar filtros
					</Button>
				</form>

				<div className="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<PaginatedTable paginatedData={customers}>
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Nombre</TableHeader>
									<TableHeader>Email</TableHeader>
									<TableHeader>noCredito</TableHeader>
									<TableHeader>Tipo cuenta</TableHeader>
									<TableHeader>Tipo suscripción</TableHeader>
									<TableHeader>Local</TableHeader>
									<TableHeader>Murguía</TableHeader>
									<TableHeader>Últ. sync</TableHeader>
									<TableHeader>Vence</TableHeader>
									<TableHeader>Lab</TableHeader>
									<TableHeader className="text-right">Acciones</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{customers.data.length === 0 ? (
									<TableRow>
										<TableCell colSpan={11}>
											<Text className="py-6 text-center text-zinc-500">
												Sin resultados.
											</Text>
										</TableCell>
									</TableRow>
								) : (
									customers.data.map((row) => (
										<TableRow key={row.id}>
											<TableCell className="font-medium">{row.name}</TableCell>
											<TableCell>{row.email}</TableCell>
											<TableCell className="font-mono text-sm">
												{row.medical_attention_identifier || "—"}
											</TableCell>
											<TableCell>
												{accountLabel[row.account_type] || row.account_type}
											</TableCell>
											<TableCell>
												{subLabel[row.subscription_type] || row.subscription_type}
											</TableCell>
											<TableCell>
												<Badge color={row.local_status === "active" ? "green" : "zinc"}>
													{row.local_status === "active" ? "Activo" : "Inactivo"}
												</Badge>
											</TableCell>
											<TableCell>
												<Badge color={row.has_murguia_sync ? "green" : "zinc"}>
													{row.has_murguia_sync ? "Sí" : "No"}
												</Badge>
											</TableCell>
											<TableCell className="whitespace-nowrap text-sm">
												{row.last_synced_murguia_at
													? new Date(row.last_synced_murguia_at).toLocaleString("es-MX")
													: "—"}
											</TableCell>
											<TableCell>
												{row.expires_at
													? new Date(row.expires_at).toLocaleDateString("es-MX")
													: "—"}
											</TableCell>
											<TableCell>{row.laboratory_purchases_count}</TableCell>
											<TableCell className="text-right">
												<div className="flex flex-wrap justify-end gap-1">
													<button
														type="button"
														onClick={() => postCheck(row.id)}
														disabled={rowBusy === row.id}
														className="text-xs font-medium text-blue-600 hover:underline disabled:opacity-50 dark:text-blue-400"
													>
														{rowBusy === row.id ? "…" : "Consultar"}
													</button>
													<button
														type="button"
														onClick={() =>
															setConfirmState({
																id: row.id,
																action: "activate",
																label: row.name,
															})
														}
														disabled={rowBusy === row.id}
														className="text-xs font-medium text-emerald-700 hover:underline disabled:opacity-50 dark:text-emerald-400"
													>
														Activar
													</button>
													<button
														type="button"
														onClick={() =>
															setConfirmState({
																id: row.id,
																action: "deactivate",
																label: row.name,
															})
														}
														disabled={rowBusy === row.id}
														className="text-xs font-medium text-red-600 hover:underline disabled:opacity-50 dark:text-red-400"
													>
														Desactivar
													</button>
													<Link
														href={route("admin.murguia-monitor.show", row.id)}
														className="text-xs font-medium text-zinc-600 hover:underline dark:text-zinc-300"
													>
														Detalle
													</Link>
												</div>
											</TableCell>
										</TableRow>
									))
								)}
							</TableBody>
						</Table>
					</PaginatedTable>
				</div>

				{confirmState && (
					<div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
						<div className="max-w-md rounded-xl border border-zinc-200 bg-white p-6 text-zinc-900 shadow-xl dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100">
							<Heading level={3} className="text-lg text-famedic-darker dark:text-white">
								{confirmState.action === "activate"
									? "Activar licencia institucional Odessa"
									: "Desactivar en Murguía"}
							</Heading>
							<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
								{confirmState.action === "activate"
									? `Se creará una suscripción INSTITUTIONAL (cuenta Odessa) y se sincronizará con Murguía en el acto para «${confirmState.label}».`
									: `Se enviará estatus «inactivo» a Murguía para «${confirmState.label}». La suscripción local no se elimina.`}
							</Text>
							<div className="mt-6 flex justify-end gap-2">
								<Button outline onClick={() => setConfirmState(null)}>
									Cancelar
								</Button>
								<Button
									onClick={runConfirmed}
									className={
										confirmState.action === "deactivate"
											? "bg-red-600 text-white hover:bg-red-500 dark:bg-red-600 dark:hover:bg-red-500"
											: ""
									}
								>
									Confirmar
								</Button>
							</div>
						</div>
					</div>
				)}

				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Activar / Desactivar se ejecutan al momento en el servidor. La carga masiva por Excel sigue
					usando cola de trabajos (
					<code className="rounded bg-zinc-100 px-1 font-mono text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
						queue:work
					</code>
					).
				</Text>
			</div>
		</AdminLayout>
	);
}
