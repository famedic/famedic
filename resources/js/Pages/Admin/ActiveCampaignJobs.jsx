import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import {
	Pagination,
	PaginationNext,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";
import { Select } from "@/Components/Catalyst/select";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { useForm } from "@inertiajs/react";
import {
	ArrowPathIcon,
	BookOpenIcon,
	ClockIcon,
	DocumentMagnifyingGlassIcon,
	SignalIcon,
} from "@heroicons/react/16/solid";

function DispatchDetail({ activeFilters, selectedDispatch }) {
	if (!selectedDispatch) return null;

	return (
		<div className="rounded-lg border border-famedic-200 bg-white p-4 shadow-sm ring-1 ring-famedic-100 dark:border-famedic-700 dark:bg-slate-900">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<div className="flex flex-wrap items-center gap-2">
						<h2 className="text-base font-semibold text-zinc-950 dark:text-white">
							Detalle del dispatch #{selectedDispatch.id}
						</h2>
						<Badge color={statusColor(selectedDispatch.status)}>
							{selectedDispatch.status_label}
						</Badge>
					</div>
					<p className="mt-1 break-all text-sm text-zinc-500">
						{selectedDispatch.event_type} · {selectedDispatch.idempotency_key}
					</p>
				</div>
				<Button
					href={route("admin.activecampaign-jobs.index", activeFilters)}
					outline
				>
					Cerrar detalle
				</Button>
			</div>

			<div className="mt-4 grid gap-4 xl:grid-cols-2">
				<div>
					<h3 className="mb-2 text-sm font-medium text-zinc-700 dark:text-slate-300">
						Contexto
					</h3>
					<div className="grid gap-2 rounded-lg bg-zinc-50 p-4 text-sm dark:bg-slate-950">
						<p>
							<strong>Estado:</strong> {selectedDispatch.status_label}
						</p>
						<p>
							<strong>Operación:</strong>{" "}
							{selectedDispatch.operation || "Sin operación"}
						</p>
						<p>
							<strong>Entidad:</strong> {selectedDispatch.entity_type || "—"} #
							{selectedDispatch.entity_id || "—"}
						</p>
						<p>
							<strong>Relacionado:</strong>{" "}
							{selectedDispatch.related_entity_type || "—"} #
							{selectedDispatch.related_entity_id || "—"}
						</p>
						<p>
							<strong>Email:</strong> {selectedDispatch.email || "—"}
						</p>
						<p>
							<strong>Creado:</strong> {formatDate(selectedDispatch.created_at)}
						</p>
						<p>
							<strong>Sincronizado:</strong>{" "}
							{formatDate(selectedDispatch.synced_at)}
						</p>
					</div>
					{selectedDispatch.last_error_full ? (
						<>
							<h3 className="mb-2 mt-4 text-sm font-medium text-zinc-700 dark:text-slate-300">
								Error
							</h3>
							<pre className="max-h-64 overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
								{selectedDispatch.last_error_full}
							</pre>
						</>
					) : null}
				</div>
				<div>
					<h3 className="mb-2 text-sm font-medium text-zinc-700 dark:text-slate-300">
						Payload sanitizado
					</h3>
					<pre className="max-h-[28rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
						{selectedDispatch.payload}
					</pre>
				</div>
			</div>
		</div>
	);
}

function formatDate(value) {
	if (!value) return "Sin registro";

	return new Intl.DateTimeFormat("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	}).format(new Date(value));
}

function statusColor(status) {
	if (status === "synced") return "emerald";
	if (status === "failed") return "red";
	if (status === "skipped") return "zinc";
	if (status === "processing") return "amber";
	return "sky";
}

function statCards(stats) {
	return [
		["Total registrados", stats.total],
		["Últimas 24 h", stats.last_24_hours],
		["Sincronizados", stats.synced],
		["Errores", stats.failed],
		["En vuelo", stats.pending],
		["Omitidos", stats.skipped],
		["Hoy", stats.today],
		["Último sync", formatDate(stats.latest_synced_at)],
	];
}

function GlossarySection({ title, tone, items }) {
	return (
		<section className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
			<div className="flex items-center gap-2">
				<BookOpenIcon className="size-4 text-zinc-400" />
				<h2 className="text-base font-semibold text-zinc-950 dark:text-white">
					{title}
				</h2>
				<Badge color={tone}>{items.length}</Badge>
			</div>
			<div className="mt-4 grid gap-3 xl:grid-cols-2">
				{items.map((item) => (
					<div
						key={item.job}
						className="rounded-md border border-zinc-200 p-3 dark:border-slate-800"
					>
						<div className="font-medium text-zinc-950 dark:text-white">
							{item.job}
						</div>
						<p className="mt-1 text-sm text-zinc-600 dark:text-slate-300">
							{item.coverage}
						</p>
						<p className="mt-2 text-xs text-zinc-500 dark:text-slate-400">
							<strong>Registros:</strong> {item.records}
						</p>
						{item.source ? (
							<p className="mt-1 text-xs text-zinc-500 dark:text-slate-400">
								<strong>Entrada:</strong> {item.source}
							</p>
						) : null}
						{item.gap ? (
							<p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
								<strong>Brecha:</strong> {item.gap}
							</p>
						) : null}
					</div>
				))}
			</div>
		</section>
	);
}

export default function ActiveCampaignJobs({
	dispatches,
	filters,
	filterOptions,
	stats,
	selectedDispatch,
	tableExists,
	glossary,
	links,
}) {
	const { data, setData, get, processing } = useForm({
		q: filters.q || "",
		status: filters.status || "",
		operation: filters.operation || "",
		event_type: filters.event_type || "",
		from: filters.from || "",
		to: filters.to || "",
	});

	const submit = (event) => {
		event.preventDefault();
		get(route("admin.activecampaign-jobs.index"), {
			data,
			preserveScroll: true,
			preserveState: true,
		});
	};

	const clear = () => {
		get(route("admin.activecampaign-jobs.index"));
	};

	const activeFilters = Object.fromEntries(
		Object.entries(filters).filter(
			([key, value]) => key !== "dispatch_id" && value !== "" && value !== null,
		),
	);

	return (
		<AdminLayout title="Jobs ActiveCampaign">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Jobs ActiveCampaign</Heading>
							<Badge color="famedic">Outbox</Badge>
							<Badge color="zinc">Auditoría</Badge>
						</div>
						<p className="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-slate-400">
							Interacciones guardadas en activecampaign_dispatches y glosario
							de cobertura para distinguir jobs con registro estructurado de
							flujos legacy.
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						{links.activecampaign_logs ? (
							<Button href={links.activecampaign_logs} outline>
								<DocumentMagnifyingGlassIcon className="size-4" />
								Logs AC
							</Button>
						) : null}
						<Button href={links.failed_jobs} outline>
							<DocumentMagnifyingGlassIcon className="size-4" />
							Jobs fallidos
						</Button>
						<Button href={route("admin.activecampaign-jobs.index")} outline>
							<ArrowPathIcon className="size-4" />
							Actualizar
						</Button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					{statCards(stats).map(([label, value]) => (
						<div
							key={label}
							className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
						>
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								{label}
							</p>
							<p className="mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">
								{value}
							</p>
						</div>
					))}
				</div>

				{!tableExists ? (
					<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
						No se encontró la tabla activecampaign_dispatches en esta base de
						datos.
					</div>
				) : (
					<>
						<form
							onSubmit={submit}
							className="grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:grid-cols-8"
						>
							<Input
								className="lg:col-span-2"
								value={data.q}
								onChange={(event) => setData("q", event.target.value)}
								placeholder="Buscar evento, email, idempotency o error"
							/>
							<Select
								value={data.status}
								onChange={(event) => setData("status", event.target.value)}
							>
								<option value="">Todos los estados</option>
								{filterOptions.statuses.map((status) => (
									<option key={status} value={status}>
										{status}
									</option>
								))}
							</Select>
							<Select
								value={data.operation}
								onChange={(event) => setData("operation", event.target.value)}
							>
								<option value="">Todas las operaciones</option>
								{filterOptions.operations.map((operation) => (
									<option key={operation} value={operation}>
										{operation}
									</option>
								))}
							</Select>
							<Select
								className="lg:col-span-2"
								value={data.event_type}
								onChange={(event) => setData("event_type", event.target.value)}
							>
								<option value="">Todos los eventos</option>
								{filterOptions.event_types.map((eventType) => (
									<option key={eventType} value={eventType}>
										{eventType}
									</option>
								))}
							</Select>
							<Input
								type="date"
								value={data.from}
								onChange={(event) => setData("from", event.target.value)}
							/>
							<Input
								type="date"
								value={data.to}
								onChange={(event) => setData("to", event.target.value)}
							/>
							<div className="flex gap-2 lg:col-span-8">
								<Button type="submit" disabled={processing}>
									Filtrar
								</Button>
								<Button type="button" outline onClick={clear}>
									Limpiar
								</Button>
							</div>
						</form>

						<DispatchDetail
							activeFilters={activeFilters}
							selectedDispatch={selectedDispatch}
						/>

						<div className="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
							<Table bleed dense wrap>
								<TableHead>
									<TableRow>
										<TableHeader>Interacción</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Entidad</TableHeader>
										<TableHeader>Intentos</TableHeader>
										<TableHeader>Último cambio</TableHeader>
										<TableHeader>Error</TableHeader>
										<TableHeader className="text-right">Detalle</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{dispatches.data.length === 0 ? (
										<TableRow>
											<TableCell
												colSpan={7}
												className="py-10 text-center text-zinc-500"
											>
												No hay interacciones ActiveCampaign con estos filtros.
											</TableCell>
										</TableRow>
									) : (
										dispatches.data.map((dispatch) => (
											<TableRow
												key={dispatch.id}
												className={
													selectedDispatch?.id === dispatch.id
														? "bg-famedic-50/70 dark:bg-famedic-950/20"
														: undefined
												}
											>
												<TableCell>
													<div className="flex items-center gap-2 font-medium text-zinc-950 dark:text-white">
														<SignalIcon className="size-4 text-zinc-400" />
														{dispatch.event_type}
													</div>
													<div className="mt-1 flex flex-wrap gap-1">
														{dispatch.operation ? (
															<Badge color="slate">{dispatch.operation}</Badge>
														) : null}
														<span className="break-all text-xs text-zinc-500">
															#{dispatch.id} · {dispatch.idempotency_key}
														</span>
													</div>
												</TableCell>
												<TableCell>
													<Badge color={statusColor(dispatch.status)}>
														{dispatch.status_label}
													</Badge>
												</TableCell>
												<TableCell>
													<div className="text-sm text-zinc-700 dark:text-slate-300">
														{dispatch.entity_type || "Sin entidad"}{" "}
														{dispatch.entity_id ? `#${dispatch.entity_id}` : ""}
													</div>
													<div className="text-xs text-zinc-500">
														{dispatch.email || "Sin email"} · Cliente{" "}
														{dispatch.customer_id || "—"}
													</div>
												</TableCell>
												<TableCell>{dispatch.attempts}</TableCell>
												<TableCell>
													<div className="flex items-center gap-1 text-sm">
														<ClockIcon className="size-4 text-zinc-400" />
														{formatDate(dispatch.updated_at)}
													</div>
													{dispatch.synced_at ? (
														<div className="mt-1 text-xs text-zinc-500">
															Sync {formatDate(dispatch.synced_at)}
														</div>
													) : null}
												</TableCell>
												<TableCell className="max-w-md">
													<span className="line-clamp-2 text-sm text-zinc-600 dark:text-slate-300">
														{dispatch.last_error || "Sin error"}
													</span>
												</TableCell>
												<TableCell className="text-right">
													<Button
														outline
														href={route("admin.activecampaign-jobs.index", {
															...activeFilters,
															dispatch_id: dispatch.id,
														})}
													>
														{selectedDispatch?.id === dispatch.id
															? "Viendo"
															: "Ver"}
													</Button>
												</TableCell>
											</TableRow>
										))
									)}
								</TableBody>
							</Table>
						</div>

						<Pagination className="mt-4">
							<PaginationPrevious href={dispatches.prev_page_url}>
								Anterior
							</PaginationPrevious>
							<PaginationNext href={dispatches.next_page_url}>
								Siguiente
							</PaginationNext>
						</Pagination>

					</>
				)}

				<GlossarySection
					title="Jobs con registro estructurado"
					tone="emerald"
					items={glossary.tracked}
				/>
				<GlossarySection
					title="Jobs legacy o sin registro estructurado"
					tone="amber"
					items={glossary.legacy}
				/>
			</div>
		</AdminLayout>
	);
}
