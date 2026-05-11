import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Divider } from "@/Components/Catalyst/divider";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { router } from "@inertiajs/react";

function statusBadgeColor(status) {
	switch (status) {
		case "ok":
			return "green";
		case "warning":
			return "amber";
		case "critical":
			return "red";
		case "mismatch":
			return "orange";
		case "cache_issue":
			return "purple";
		case "sin_mapeo":
			return "zinc";
		default:
			return "zinc";
	}
}

function statusLabel(status) {
	switch (status) {
		case "ok":
			return "OK";
		case "warning":
			return "Advertencia";
		case "critical":
			return "Crítico";
		case "mismatch":
			return "Discrepancia";
		case "cache_issue":
			return "Caché / .env";
		case "sin_mapeo":
			return "Sin mapeo";
		default:
			return status;
	}
}

function summaryLabel(key) {
	switch (key) {
		case "ok":
			return "OK";
		case "warning":
			return "Advertencias";
		case "critical":
			return "Críticos";
		case "mismatch":
			return "Discrepancias";
		case "cache_issue":
			return "Caché / .env";
		case "sin_mapeo":
			return "Sin mapeo a config()";
		default:
			return key;
	}
}

export default function ConfigMonitorIndex({ report, canManageMetadata }) {
	const onRefresh = () => {
		router.post(route("admin.config-monitor.refresh"));
	};

	return (
		<AdminLayout title="Config Monitor">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Config Monitor</Heading>
						<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
							Vista solo lectura: la fuente de verdad en tiempo de ejecución es{" "}
							<code className="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">config()</code>.
							La referencia de archivo{" "}
							<code className="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">.env</code> se
							lee de forma controlada para comparar (no se guardan secretos en base de datos). Al final se
							listan todas las claves del archivo que no estén ya en metadatos (con mapeo opcional en{" "}
							<code className="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">
								config/config_monitor.php
							</code>
							).
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						{canManageMetadata ? (
							<Button href={route("admin.config-monitor.metadata.index")} outline>
								Metadatos
							</Button>
						) : null}
						<Button onClick={onRefresh}>Actualizar vista</Button>
					</div>
				</div>

				<div className="flex flex-wrap gap-3 text-sm">
					<Badge color={report.configuration_cached ? "amber" : "emerald"}>
						{report.configuration_cached
							? "Configuración en caché (config:cache)"
							: "Configuración no cacheada"}
					</Badge>
					<Badge color={report.dotenv_file_loaded ? "sky" : "zinc"}>
						{report.dotenv_file_loaded
							? "Archivo .env legible para referencia"
							: "Sin archivo .env legible en despliegue"}
					</Badge>
				</div>

				<div className="flex flex-wrap gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					{Object.entries(report.summary).map(([k, v]) => (
						<div key={k} className="flex items-center gap-2">
							<Text className="text-zinc-500">{summaryLabel(k)}</Text>
							<Badge color="slate">{v}</Badge>
						</div>
					))}
				</div>

				{report.groups.length === 0 ? (
					<Text>
						No hay grupos o claves monitoreadas.{" "}
						{canManageMetadata ? (
							<>
								Define metadatos en{" "}
								<a
									className="text-famedic-dark underline dark:text-famedic-lime"
									href={route("admin.config-monitor.metadata.index")}
								>
									Metadatos
								</a>
								.
							</>
						) : (
							"Un administrador con permiso de metadatos debe cargar la configuración inicial."
						)}
					</Text>
				) : null}

				{report.groups.map((group) => (
					<section key={group.id} className="space-y-3">
						<div className="flex items-baseline justify-between gap-4">
							<Subheading>{group.name}</Subheading>
							<Text className="text-xs text-zinc-500">{group.slug}</Text>
						</div>
						<Divider />
						<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
							<Table className="min-w-[880px] [--gutter:theme(spacing.4)]">
								<TableHead>
									<TableRow>
										<TableHeader>Variable / etiqueta</TableHeader>
										<TableHeader>config()</TableHeader>
										<TableHeader>Referencia .env</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Notas</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{group.rows.map((row) => (
										<TableRow key={row.id}>
											<TableCell>
												<div className="space-y-1">
													<Text>
														<strong>{row.label}</strong>
													</Text>
													<Text className="text-xs text-zinc-500">{row.env_key}</Text>
													<Text className="font-mono text-xs text-zinc-500">{row.config_key}</Text>
												</div>
											</TableCell>
											<TableCell className="max-w-xs break-all font-mono text-xs">
												{row.config_display ?? "—"}
											</TableCell>
											<TableCell className="max-w-xs break-all font-mono text-xs">
												{row.env_display ?? "—"}
											</TableCell>
											<TableCell>
												<Badge color={statusBadgeColor(row.status)}>{statusLabel(row.status)}</Badge>
											</TableCell>
											<TableCell className="max-w-sm text-xs text-zinc-600 dark:text-zinc-400">
												{row.notes?.length ? (
													<ul className="list-inside list-disc space-y-1">
														{row.notes.map((n) => (
															<li key={n}>{n}</li>
														))}
													</ul>
												) : (
													"—"
												)}
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>
					</section>
				))}

				{report.env_file_coverage?.count > 0 ? (
					<section className="space-y-3">
						<div className="flex flex-wrap items-baseline justify-between gap-4">
							<Subheading>Resto de variables del archivo .env</Subheading>
							<Text className="text-xs text-zinc-500">
								{report.env_file_coverage.count} claves (excluye las ya definidas en metadatos)
							</Text>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Incluye claves solo de frontend (p. ej. VITE_*) o sin entrada en{" "}
							<code className="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">env_to_config</code>: se
							muestran como referencia; el estado &quot;Sin mapeo&quot; indica que aún no hay comparación
							con <code className="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">config()</code>.
						</Text>
						<Divider />
						<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
							<Table className="min-w-[880px] [--gutter:theme(spacing.4)]">
								<TableHead>
									<TableRow>
										<TableHeader>Variable / etiqueta</TableHeader>
										<TableHeader>config()</TableHeader>
										<TableHeader>Referencia .env</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Notas</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{report.env_file_coverage.rows.map((row) => (
										<TableRow key={row.id}>
											<TableCell>
												<div className="space-y-1">
													<Text>
														<strong>{row.label}</strong>
													</Text>
													<Text className="text-xs text-zinc-500">{row.env_key}</Text>
													<Text className="font-mono text-xs text-zinc-500">{row.config_key}</Text>
												</div>
											</TableCell>
											<TableCell className="max-w-xs break-all font-mono text-xs">
												{row.config_display ?? "—"}
											</TableCell>
											<TableCell className="max-w-xs break-all font-mono text-xs">
												{row.env_display ?? "—"}
											</TableCell>
											<TableCell>
												<Badge color={statusBadgeColor(row.status)}>{statusLabel(row.status)}</Badge>
											</TableCell>
											<TableCell className="max-w-sm text-xs text-zinc-600 dark:text-zinc-400">
												{row.notes?.length ? (
													<ul className="list-inside list-disc space-y-1">
														{row.notes.map((n) => (
															<li key={n}>{n}</li>
														))}
													</ul>
												) : (
													"—"
												)}
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>
					</section>
				) : null}
			</div>
		</AdminLayout>
	);
}
