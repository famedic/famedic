import { Link, router } from "@inertiajs/react";
import { ArrowDownTrayIcon, EyeIcon, PlusIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";

export default function Index({ runs, dashboard, successMessage }) {
	return (
		<AdminLayout title="ODESSA — conciliaciones">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>ODESSA / Conciliaciones</Heading>
						<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							Historial auditable de corridas, snapshots y revisión manual.
						</Text>
					</div>
					<Button href={route("admin.odessa.reconciliations.create")}>
						<PlusIcon data-slot="icon" />
						Nueva conciliación
					</Button>
				</div>

				{successMessage ? <Notice>{successMessage}</Notice> : null}

				<div className="grid gap-3 md:grid-cols-4">
					<Metric label="Total corridas" value={dashboard.total_runs} />
					<Metric label="Pendientes revisión" value={dashboard.pending_review} color="amber" />
					<Metric
						label="Última: colaboradores"
						value={dashboard.latest_run?.unique_collaborators || 0}
					/>
					<Metric
						label="Última: no registrados"
						value={dashboard.latest_run?.not_found_count || 0}
						color="red"
					/>
				</div>

				<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Archivo</TableHeader>
								<TableHeader>Murguía</TableHeader>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Colaboradores</TableHeader>
								<TableHeader>Confirmados</TableHeader>
								<TableHeader>Revisión</TableHeader>
								<TableHeader>No registrados</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{runs.data.length === 0 ? (
								<TableRow>
									<TableCell colSpan={10} className="py-10 text-center text-zinc-500">
										Todavía no hay conciliaciones persistidas.
									</TableCell>
								</TableRow>
							) : (
								runs.data.map((run) => (
									<TableRow key={run.id}>
										<TableCell className="text-xs">{run.completed_at || run.created_at}</TableCell>
										<TableCell className="min-w-64">{run.source_filename}</TableCell>
										<TableCell className="min-w-48">{run.murguia_filename || "—"}</TableCell>
										<TableCell>{run.uploaded_by || "—"}</TableCell>
										<TableCell>{run.unique_collaborators}</TableCell>
										<TableCell>{run.confirmed_count}</TableCell>
										<TableCell>
											<div>{run.manual_review_count}</div>
											<div className="text-xs text-amber-600">
												{run.pending_review_count} pendientes
											</div>
										</TableCell>
										<TableCell>{run.not_found_count}</TableCell>
										<TableCell>
											<Badge color={run.status === "COMPLETED" ? "green" : "zinc"}>
												{run.status}
											</Badge>
										</TableCell>
										<TableCell className="text-right">
											<div className="flex justify-end gap-2">
												<Button href={run.show_url} outline>
													<EyeIcon data-slot="icon" />
													Ver
												</Button>
												<a
													href={run.export_url}
													className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
												>
													<ArrowDownTrayIcon className="size-4" />
													XLSX
												</a>
											</div>
										</TableCell>
									</TableRow>
								))
							)}
						</TableBody>
					</Table>
				</div>

				{runs.last_page > 1 ? (
					<div className="flex flex-wrap gap-2">
						{runs.links.map((link, index) => (
							<button
								key={`${link.label}-${index}`}
								type="button"
								disabled={!link.url}
								onClick={() => link.url && router.visit(link.url)}
								className={`rounded-md px-3 py-1 text-sm ${
									link.active
										? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker"
										: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
								}`}
								dangerouslySetInnerHTML={{ __html: link.label }}
							/>
						))}
					</div>
				) : null}
			</div>
		</AdminLayout>
	);
}

function Metric({ label, value, color = "zinc" }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs text-zinc-500">{label}</Text>
			<p className={`mt-1 text-2xl font-semibold tabular-nums ${color === "red" ? "text-red-600" : color === "amber" ? "text-amber-600" : ""}`}>
				{Number(value || 0).toLocaleString("es-MX")}
			</p>
		</div>
	);
}

function Notice({ children }) {
	return (
		<div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
			{children}
		</div>
	);
}
