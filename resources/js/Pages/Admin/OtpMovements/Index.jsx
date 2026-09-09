import { useMemo, useState } from "react";
import { Link, useForm } from "@inertiajs/react";
import axios from "axios";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";

function SummaryCard({ label, value }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<p className="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
				{value}
			</p>
		</div>
	);
}

function StatusBadge({ status, label, color }) {
	return <Badge color={color || "zinc"}>{label || status}</Badge>;
}

function SmsDiagnosticDialog({ open, onClose, smsDiagnostic }) {
	const [destination, setDestination] = useState("");
	const [mode, setMode] = useState("send_only");
	const [confirmed, setConfirmed] = useState(false);
	const [processing, setProcessing] = useState(false);
	const [error, setError] = useState(null);
	const [result, setResult] = useState(null);

	const modes = smsDiagnostic?.modes || {};
	const dlrMode = modes.send_with_dlr || {};
	const canSubmit =
		smsDiagnostic?.enabled &&
		smsDiagnostic?.can_send &&
		destination.trim() &&
		confirmed &&
		!processing &&
		(mode !== "send_with_dlr" || dlrMode.enabled);

	const close = () => {
		if (!processing) {
			onClose();
		}
	};

	const submit = async (event) => {
		event.preventDefault();
		if (!canSubmit) {
			return;
		}

		setProcessing(true);
		setError(null);
		setResult(null);

		try {
			const response = await axios.post(route("admin.otp-movements-monitor.test-sms"), {
				destination,
				mode,
				confirm: confirmed,
			});
			setResult(response.data.data);
			setConfirmed(false);
		} catch (err) {
			setError(
				err.response?.data?.message ||
					err.response?.data?.errors?.destination?.[0] ||
					"No fue posible enviar la prueba SMS.",
			);
		} finally {
			setProcessing(false);
		}
	};

	return (
		<Dialog open={open} onClose={close} size="2xl">
			<form onSubmit={submit}>
				<DialogTitle>Probar envío SMS</DialogTitle>
				<DialogDescription>
					Esta herramienta envía un mensaje real de diagnóstico por Vonage sin crear
					usuario, customer, challenge ni código OTP.
				</DialogDescription>

				<DialogBody className="space-y-5">
					<div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
						Esta acción enviará un SMS real y puede generar costo.
					</div>

					{(!smsDiagnostic?.enabled || !smsDiagnostic?.can_send) && (
						<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
							{!smsDiagnostic?.enabled
								? "La herramienta está apagada por configuración."
								: "Tu administrador no tiene el permiso otp-movements.test-sms."}
						</div>
					)}

					<label className="block space-y-1 text-sm">
						<span className="font-medium text-zinc-800 dark:text-zinc-100">
							Destino telefónico
						</span>
						<input
							type="tel"
							value={destination}
							onChange={(event) => setDestination(event.target.value)}
							placeholder="+528112345678"
							disabled={processing}
							className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
						/>
					</label>

					<div className="space-y-2 text-sm">
						<p className="font-medium text-zinc-800 dark:text-zinc-100">
							Destinos permitidos
						</p>
						{smsDiagnostic?.allowed_destinations?.length ? (
							<div className="flex flex-wrap gap-2">
								{smsDiagnostic.allowed_destinations.map((item) => (
									<Badge key={item} color="zinc">
										{item}
									</Badge>
								))}
							</div>
						) : (
							<Text className="text-sm text-zinc-500">
								No hay allowlist configurada; el backend rechazará cualquier envío.
							</Text>
						)}
					</div>

					<div className="space-y-2 text-sm">
						<p className="font-medium text-zinc-800 dark:text-zinc-100">
							Modo de prueba
						</p>
						<label className="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
							<input
								type="radio"
								name="sms-test-mode"
								value="send_only"
								checked={mode === "send_only"}
								onChange={() => setMode("send_only")}
								disabled={processing}
								className="mt-1"
							/>
							<span>
								<span className="block font-medium">Solo envío</span>
								<span className="block text-zinc-500">
									Nunca adjunta callback DLR; sirve en local.
								</span>
							</span>
						</label>
						<label className="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
							<input
								type="radio"
								name="sms-test-mode"
								value="send_with_dlr"
								checked={mode === "send_with_dlr"}
								onChange={() => setMode("send_with_dlr")}
								disabled={processing || !dlrMode.enabled}
								className="mt-1"
							/>
							<span>
								<span className="block font-medium">Envío + seguimiento DLR</span>
								<span className="block text-zinc-500">
									Adjunta callback per_message solo con base HTTPS pública válida.
								</span>
								{!dlrMode.enabled && dlrMode.disabled_reason && (
									<span className="mt-1 block text-amber-700 dark:text-amber-300">
										{dlrMode.disabled_reason}
									</span>
								)}
							</span>
						</label>
					</div>

					<label className="flex items-start gap-3 text-sm">
						<input
							type="checkbox"
							checked={confirmed}
							onChange={(event) => setConfirmed(event.target.checked)}
							disabled={processing}
							className="mt-1"
						/>
						<span>Confirmo que deseo enviar este SMS real de diagnóstico.</span>
					</label>

					{error && (
						<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
							{error}
						</div>
					)}

					{result && (
						<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900/60">
							<p className="font-semibold text-zinc-900 dark:text-zinc-100">
								{result.diagnosis}
							</p>
							<dl className="mt-3 grid gap-2 sm:grid-cols-2">
								<div>
									<dt className="text-zinc-500">Correlation ID</dt>
									<dd className="break-all">{result.correlation_id}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Fecha y hora</dt>
									<dd>{new Date(result.sent_at).toLocaleString("es-MX")}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Entorno</dt>
									<dd>{result.environment}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Destino</dt>
									<dd>{result.destination_masked}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Modo</dt>
									<dd>{result.mode === "send_with_dlr" ? "Envío + DLR" : "Solo envío"}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Status Vonage</dt>
									<dd>{result.vonage_status ?? "No disponible"}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Mensaje proveedor</dt>
									<dd>{result.provider_message_id_prefix || "No disponible"}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Callback DLR</dt>
									<dd>
										{result.callback?.applied
											? `Aplicado (${result.callback.host})`
											: result.callback?.disabled_reason || "No aplicado"}
									</dd>
								</div>
							</dl>
						</div>
					)}
				</DialogBody>

				<DialogActions>
					<Button type="button" outline onClick={close} disabled={processing}>
						Cerrar
					</Button>
					<Button type="submit" disabled={!canSubmit}>
						{processing ? "Enviando..." : "Enviar SMS de prueba"}
					</Button>
				</DialogActions>
			</form>
		</Dialog>
	);
}

export default function OtpMovementsIndex({ events, summary, filters, options, smsDiagnostic }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		flow: filters.flow || "",
		status: filters.status || "",
		stage: filters.stage || "",
		channel: filters.channel || "",
		provider: filters.provider || "",
		endpoint: filters.endpoint || "",
		correlation_id: filters.correlation_id || "",
		challenge_id: filters.challenge_id || "",
		user_id: filters.user_id || "",
		customer_id: filters.customer_id || "",
		destination: filters.destination || "",
		failed_only: filters.failed_only || "",
		replay_only: filters.replay_only || "",
		sms_delivery_status: filters.sms_delivery_status || "",
	});

	const [showFilters, setShowFilters] = useState(false);
	const [smsDiagnosticOpen, setSmsDiagnosticOpen] = useState(false);

	const showUpdateButton = useMemo(
		() =>
			Object.keys(data).some((key) => (data[key] || "") !== (filters[key] || "")),
		[data, filters],
	);

	const filtersCount = useMemo(
		() =>
			Object.keys(data).filter(
				(key) => !["start_date", "end_date"].includes(key) && data[key],
			).length,
		[data],
	);

	const applyFilters = (e) => {
		e?.preventDefault?.();
		if (!processing) {
			get(route("admin.otp-movements-monitor.index"), { preserveState: true });
		}
	};

	const clearFilters = () => {
		setData({
			start_date: filters.start_date,
			end_date: filters.end_date,
			flow: "",
			status: "",
			stage: "",
			channel: "",
			provider: "",
			endpoint: "",
			correlation_id: "",
			challenge_id: "",
			user_id: "",
			customer_id: "",
			destination: "",
			failed_only: "",
			replay_only: "",
			sms_delivery_status: "",
		});
		get(route("admin.otp-movements-monitor.index"), {
			data: {
				start_date: filters.start_date,
				end_date: filters.end_date,
			},
			preserveState: true,
		});
	};

	return (
		<AdminLayout title="Movimientos OTP">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div>
						<Heading>Movimientos OTP</Heading>
						<Text className="mt-1 text-sm text-zinc-500">
							Reconstrucción de flujos OTP Akúbica V1 para diagnóstico
							administrativo.
						</Text>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<Button
							outline
							type="button"
							onClick={() => setSmsDiagnosticOpen(true)}
							disabled={!smsDiagnostic?.can_send}
						>
							Probar envío SMS
						</Button>
						<Button outline type="button" onClick={() => setShowFilters((v) => !v)}>
							Filtros
							<FilterCountBadge count={filtersCount} />
						</Button>
						<Button
							outline
							type="button"
							onClick={clearFilters}
							disabled={filtersCount === 0}
						>
							Limpiar filtros
						</Button>
						<Button disabled={processing || !showUpdateButton} onClick={applyFilters}>
							Actualizar resultados
						</Button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-8">
					<SummaryCard label="Solicitudes" value={summary.requests ?? 0} />
					<SummaryCard label="Envíos intentados" value={summary.delivery_attempts ?? 0} />
					<SummaryCard label="Aceptados por proveedor" value={summary.provider_accepted ?? 0} />
					<SummaryCard label="SMS entregados" value={summary.sms_delivered ?? 0} />
					<SummaryCard label="SMS no entregados" value={summary.sms_not_delivered ?? 0} />
					<SummaryCard label="Verificados" value={summary.verified ?? 0} />
					<SummaryCard label="Fallidos" value={summary.failed ?? 0} />
					<SummaryCard label="Replays" value={summary.replays ?? 0} />
				</div>

				{showFilters && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<div className="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
							<label className="space-y-1 text-sm">
								<span>Desde</span>
								<input
									type="date"
									value={data.start_date}
									onChange={(e) => setData("start_date", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>Hasta</span>
								<input
									type="date"
									value={data.end_date}
									onChange={(e) => setData("end_date", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>Flujo</span>
								<select
									value={data.flow}
									onChange={(e) => setData("flow", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.flows?.map((item) => (
										<option key={item.value} value={item.value}>
											{item.label}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Estado</span>
								<select
									value={data.status}
									onChange={(e) => setData("status", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.statuses?.map((item) => (
										<option key={item.value} value={item.value}>
											{item.label}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Evento / etapa</span>
								<select
									value={data.stage}
									onChange={(e) => setData("stage", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.stages?.map((item) => (
										<option key={item.value} value={item.value}>
											{item.label}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Canal</span>
								<select
									value={data.channel}
									onChange={(e) => setData("channel", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.channels?.map((item) => (
										<option key={item} value={item}>
											{item}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Entrega SMS</span>
								<select
									value={data.sms_delivery_status}
									onChange={(e) => setData("sms_delivery_status", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.sms_delivery_statuses?.map((item) => (
										<option key={item.value} value={item.value}>
											{item.label}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Proveedor</span>
								<select
									value={data.provider}
									onChange={(e) => setData("provider", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{options.providers?.map((item) => (
										<option key={item} value={item}>
											{item}
										</option>
									))}
								</select>
							</label>
							<label className="space-y-1 text-sm">
								<span>Endpoint</span>
								<input
									value={data.endpoint}
									onChange={(e) => setData("endpoint", e.target.value)}
									placeholder="api/v1/auth/login..."
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>correlation_id</span>
								<input
									value={data.correlation_id}
									onChange={(e) => setData("correlation_id", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>challenge_id</span>
								<input
									value={data.challenge_id}
									onChange={(e) => setData("challenge_id", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>Usuario (ID)</span>
								<input
									value={data.user_id}
									onChange={(e) => setData("user_id", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm">
								<span>Customer (ID)</span>
								<input
									value={data.customer_id}
									onChange={(e) => setData("customer_id", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="space-y-1 text-sm md:col-span-2">
								<span>Teléfono o correo (últimos dígitos / dominio)</span>
								<input
									value={data.destination}
									onChange={(e) => setData("destination", e.target.value)}
									placeholder="5678 o usuario@dominio.com"
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-600 dark:bg-zinc-800"
								/>
							</label>
							<label className="flex items-center gap-2 text-sm">
								<input
									type="checkbox"
									checked={Boolean(data.failed_only)}
									onChange={(e) =>
										setData("failed_only", e.target.checked ? "1" : "")
									}
								/>
								Solo fallidos
							</label>
							<label className="flex items-center gap-2 text-sm">
								<input
									type="checkbox"
									checked={Boolean(data.replay_only)}
									onChange={(e) =>
										setData("replay_only", e.target.checked ? "1" : "")
									}
								/>
								Solo replays / conflictos
							</label>
						</div>
					</div>
				)}

				{events?.data?.length ? (
					<PaginatedTable paginatedData={events}>
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Fecha</TableHeader>
									<TableHeader>Flujo</TableHeader>
									<TableHeader>Etapa</TableHeader>
									<TableHeader>Estado</TableHeader>
									<TableHeader>Canal</TableHeader>
									<TableHeader>Destino</TableHeader>
									<TableHeader>Entrega SMS</TableHeader>
									<TableHeader>Usuario</TableHeader>
									<TableHeader>Proveedor</TableHeader>
									<TableHeader>Detalle</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{events.data.map((row) => (
									<TableRow key={row.id}>
										<TableCell className="whitespace-nowrap text-sm">
											{row.occurred_at
												? new Date(row.occurred_at).toLocaleString("es-MX")
												: "—"}
										</TableCell>
										<TableCell>
											<div className="space-y-1">
												<Text className="text-sm font-medium">
													{row.flow_label}
												</Text>
												{row.partial_traceability && (
													<Badge color="amber">Trazabilidad parcial</Badge>
												)}
											</div>
										</TableCell>
										<TableCell className="text-sm">{row.stage_label}</TableCell>
										<TableCell>
											<StatusBadge
												status={row.status}
												label={row.status_label}
												color={row.status_color}
											/>
										</TableCell>
										<TableCell>{row.channel || "—"}</TableCell>
										<TableCell>{row.destination_masked || "—"}</TableCell>
										<TableCell>
											{row.sms_delivery ? (
												<StatusBadge
													status={row.sms_delivery.status}
													label={row.sms_delivery.label}
													color={row.sms_delivery.color}
												/>
											) : (
												"—"
											)}
										</TableCell>
										<TableCell className="text-sm">
											{row.user?.name || row.user?.user_id || "—"}
										</TableCell>
										<TableCell className="text-sm">
											{row.provider || "—"}
											{row.is_replay && (
												<div>
													<Badge color="amber">Replay</Badge>
												</div>
											)}
											{row.is_idempotency_conflict && (
												<div>
													<Badge color="red">Conflicto</Badge>
												</div>
											)}
										</TableCell>
										<TableCell>
											<Link
												href={route(
													"admin.otp-movements-monitor.show",
													row.movement_key,
												)}
												className="text-sm font-medium text-lime-700 hover:underline dark:text-lime-400"
											>
												Ver línea de tiempo
											</Link>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</PaginatedTable>
				) : (
					<div className="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
						<Text>No hay movimientos OTP para los filtros seleccionados.</Text>
					</div>
				)}
			</div>
			<SmsDiagnosticDialog
				open={smsDiagnosticOpen}
				onClose={() => setSmsDiagnosticOpen(false)}
				smsDiagnostic={smsDiagnostic}
			/>
		</AdminLayout>
	);
}
