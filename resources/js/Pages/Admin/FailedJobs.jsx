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
	ClockIcon,
	DocumentMagnifyingGlassIcon,
} from "@heroicons/react/16/solid";

function formatDate(value) {
	if (!value) return "Sin registro";

	return new Intl.DateTimeFormat("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	}).format(new Date(value));
}

function statCards(stats) {
	return [
		["Total", stats.total],
		["Últimas 24 h", stats.last_24_hours],
		["Hoy", stats.today],
		["Último fallo", formatDate(stats.latest_failed_at)],
	];
}

export default function FailedJobs({
	failedJobs,
	filters,
	stats,
	queues,
	connections,
	selectedJob,
	tableExists,
}) {
	const { data, setData, get, processing } = useForm({
		q: filters.q || "",
		queue: filters.queue || "",
		connection: filters.connection || "",
		from: filters.from || "",
		to: filters.to || "",
	});

	const submit = (event) => {
		event.preventDefault();
		get(route("admin.failed-jobs.index"), {
			data,
			preserveScroll: true,
			preserveState: true,
		});
	};

	const clear = () => {
		get(route("admin.failed-jobs.index"));
	};
	const activeFilters = Object.fromEntries(
		Object.entries(filters).filter(
			([key, value]) => key !== "failed_job" && value !== "" && value !== null,
		),
	);

	return (
		<AdminLayout title="Jobs fallidos">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Jobs fallidos</Heading>
						<p className="mt-1 text-sm text-zinc-500 dark:text-slate-400">
							Registro de fallos de cola guardados por Laravel.
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.logs-general.manage")} outline>
							<DocumentMagnifyingGlassIcon className="size-4" />
							Logs generales
						</Button>
						<Button href={route("admin.failed-jobs.index")} outline>
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
						No se encontró la tabla failed_jobs en esta base de datos.
					</div>
				) : (
					<>
						<form
							onSubmit={submit}
							className="grid gap-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-6"
						>
							<Input
								className="md:col-span-2"
								value={data.q}
								onChange={(event) => setData("q", event.target.value)}
								placeholder="Buscar job, UUID o error"
							/>
							<Select
								value={data.connection}
								onChange={(event) => setData("connection", event.target.value)}
							>
								<option value="">Todas las conexiones</option>
								{connections.map((connection) => (
									<option key={connection} value={connection}>
										{connection}
									</option>
								))}
							</Select>
							<Select
								value={data.queue}
								onChange={(event) => setData("queue", event.target.value)}
							>
								<option value="">Todas las colas</option>
								{queues.map((queue) => (
									<option key={queue} value={queue}>
										{queue}
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
							<div className="flex gap-2 md:col-span-6">
								<Button type="submit" disabled={processing}>
									Filtrar
								</Button>
								<Button type="button" outline onClick={clear}>
									Limpiar
								</Button>
							</div>
						</form>

						<div className="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
							<Table bleed dense wrap>
								<TableHead>
									<TableRow>
										<TableHeader>Job</TableHeader>
										<TableHeader>Cola</TableHeader>
										<TableHeader>Falló</TableHeader>
										<TableHeader>Error</TableHeader>
										<TableHeader className="text-right">Detalle</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{failedJobs.data.length === 0 ? (
										<TableRow>
											<TableCell
												colSpan={5}
												className="py-10 text-center text-zinc-500"
											>
												No hay jobs fallidos con estos filtros.
											</TableCell>
										</TableRow>
									) : (
										failedJobs.data.map((job) => (
											<TableRow key={job.id}>
												<TableCell>
													<div className="font-medium text-zinc-950 dark:text-white">
														{job.job_name}
													</div>
													<div className="mt-1 max-w-md break-all text-xs text-zinc-500">
														{job.uuid}
													</div>
												</TableCell>
												<TableCell>
													<div className="flex flex-wrap gap-1">
														<Badge color="zinc">{job.connection}</Badge>
														<Badge color="slate">{job.queue}</Badge>
													</div>
												</TableCell>
												<TableCell>
													<div className="flex items-center gap-1 text-sm">
														<ClockIcon className="size-4 text-zinc-400" />
														{formatDate(job.failed_at)}
													</div>
												</TableCell>
												<TableCell className="max-w-xl">
													<span className="line-clamp-2 text-sm text-zinc-600 dark:text-slate-300">
														{job.exception_summary}
													</span>
												</TableCell>
												<TableCell className="text-right">
													<Button
														outline
														href={route("admin.failed-jobs.index", {
															...activeFilters,
															failed_job: job.id,
														})}
													>
														Ver
													</Button>
												</TableCell>
											</TableRow>
										))
									)}
								</TableBody>
							</Table>
						</div>

						<Pagination className="mt-4">
							<PaginationPrevious href={failedJobs.prev_page_url}>
								Anterior
							</PaginationPrevious>
							<PaginationNext href={failedJobs.next_page_url}>
								Siguiente
							</PaginationNext>
						</Pagination>

						{selectedJob && (
							<div className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
								<div className="flex flex-wrap items-start justify-between gap-3">
									<div>
										<h2 className="text-base font-semibold text-zinc-950 dark:text-white">
											Detalle #{selectedJob.id}
										</h2>
										<p className="mt-1 text-sm text-zinc-500">
											{selectedJob.job_name}
										</p>
									</div>
									<Button href={route("admin.failed-jobs.index", activeFilters)} outline>
										Cerrar detalle
									</Button>
								</div>

								<div className="mt-4 grid gap-4 xl:grid-cols-2">
									<div>
										<h3 className="mb-2 text-sm font-medium text-zinc-700 dark:text-slate-300">
											Excepción
										</h3>
										<pre className="max-h-[28rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
											{selectedJob.exception}
										</pre>
									</div>
									<div>
										<h3 className="mb-2 text-sm font-medium text-zinc-700 dark:text-slate-300">
											Payload
										</h3>
										<pre className="max-h-[28rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
											{selectedJob.payload}
										</pre>
									</div>
								</div>
							</div>
						)}
					</>
				)}
			</div>
		</AdminLayout>
	);
}
