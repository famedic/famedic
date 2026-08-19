import { Link, useForm } from "@inertiajs/react";
import { ArrowLeftIcon, DocumentMagnifyingGlassIcon, InboxArrowDownIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";

export default function Import({ preview, canImport, importEnabled, successMessage, errors = {} }) {
	const form = useForm({ source_file: null });
	const confirmForm = useForm({ run_uuid: preview?.meta?.run_uuid || "", source_file: null, confirmation: "" });
	const submit = (event) => {
		event.preventDefault();
		form.post(route("admin.odessa.pre-enrollments.import.preview"), {
			forceFormData: true,
			preserveScroll: true,
		});
	};
	const confirm = (event) => {
		event.preventDefault();
		confirmForm
			.transform((data) => ({ ...data, run_uuid: preview?.meta?.run_uuid || "" }))
			.post(route("admin.odessa.pre-enrollments.import.confirm"), {
				forceFormData: true,
				preserveScroll: true,
			});
	};

	return (
		<AdminLayout title="Preview preafiliaciones ODESSA">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<Link href={route("admin.odessa.pre-enrollments.index")} className="inline-flex items-center gap-1 text-sm text-famedic-dark hover:underline dark:text-famedic-lime">
					<ArrowLeftIcon className="size-4" />
					Volver a preafiliaciones
				</Link>

				<div>
					<Heading>Preview import ODESSA</Heading>
					<Text className="mt-1 text-sm text-zinc-500">
						Analiza el Excel y detecta duplicados/conflictos. No crea registros ni genera noCredito.
					</Text>
				</div>

				{successMessage ? <Notice>{successMessage}</Notice> : null}

				<form onSubmit={submit} className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
					<input
						type="file"
						accept=".xlsx,.xls"
						onChange={(event) => form.setData("source_file", event.target.files?.[0] || null)}
						className="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700"
					/>
					{form.errors.source_file || errors.source_file ? <Text className="mt-2 text-sm text-red-600">{form.errors.source_file || errors.source_file}</Text> : null}
					<div className="mt-4 flex justify-end">
						<Button type="submit" disabled={form.processing || !form.data.source_file}>
							<DocumentMagnifyingGlassIcon data-slot="icon" />
							{form.processing ? "Analizando…" : "Analizar Excel"}
						</Button>
					</div>
				</form>

				{preview ? <PreviewResult preview={preview} /> : null}
				{preview ? (
					<form onSubmit={confirm} className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
						<div className="flex flex-wrap items-start justify-between gap-4">
							<div>
								<p className="text-sm font-semibold">Confirmación de importación</p>
								<Text className="mt-1 text-sm text-zinc-500">
									El análisis no ha guardado registros. Vuelve a seleccionar el mismo archivo antes de confirmar.
								</Text>
								<Text className="mt-1 text-xs text-zinc-500">
									Expira: {preview.meta.expires_at || "—"}
								</Text>
							</div>
							<Badge color={preview.meta.importable ? "green" : "zinc"}>
								{Number(preview.meta.ready_rows || 0).toLocaleString("es-MX")} importables
							</Badge>
						</div>
						<div className="mt-4 grid gap-3 md:grid-cols-[1fr_12rem_auto]">
							<input
								type="file"
								accept=".xlsx,.xls"
								onChange={(event) => confirmForm.setData("source_file", event.target.files?.[0] || null)}
								className="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700"
							/>
							<input
								value={confirmForm.data.confirmation}
								onChange={(event) => confirmForm.setData("confirmation", event.target.value)}
								placeholder="IMPORTAR"
								className="rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
							/>
							<Button
								type="submit"
								disabled={
									confirmForm.processing ||
									!preview.meta.importable ||
									!canImport ||
									!importEnabled ||
									!confirmForm.data.source_file ||
									confirmForm.data.confirmation !== "IMPORTAR"
								}
							>
								<InboxArrowDownIcon data-slot="icon" />
								Confirmar importación de {Number(preview.meta.ready_rows || 0).toLocaleString("es-MX")} registros
							</Button>
						</div>
						{errors.import || confirmForm.errors.import || confirmForm.errors.source_file ? (
							<Text className="mt-2 text-sm text-red-600">{errors.import || confirmForm.errors.import || confirmForm.errors.source_file}</Text>
						) : null}
						{!importEnabled ? <Text className="mt-2 text-sm text-amber-600">La importación persistente está deshabilitada por configuración.</Text> : null}
						{!canImport ? <Text className="mt-2 text-sm text-amber-600">Tu usuario no tiene permiso para confirmar importaciones.</Text> : null}
					</form>
				) : null}
			</div>
		</AdminLayout>
	);
}

function PreviewResult({ preview }) {
	const count = (key) => preview.summary?.[key] || 0;
	return (
		<div className="space-y-4">
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
					<Metric label="Filas" value={preview.meta.row_count} />
				<Metric label="READY_TO_PRELOAD" value={count("READY_TO_PRELOAD")} color="green" />
				<Metric label="EXISTING_FAMEDIC_USER" value={count("EXISTING_FAMEDIC_USER") + count("EXISTING_ODESSA_ACCOUNT")} color="amber" />
				<Metric label="OTHER_EMAIL" value={count("OTHER_EMAIL")} color="orange" />
				<Metric label="POSSIBLE_DUPLICATE" value={count("POSSIBLE_DUPLICATE")} color="violet" />
				<Metric label="BLOCKED" value={count("BLOCKED")} color="red" />
			</div>
			<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>Fila</TableHeader>
							<TableHeader>Acción</TableHeader>
							<TableHeader>Diagnóstico</TableHeader>
							<TableHeader>FAMEDIC</TableHeader>
							<TableHeader>Identidad</TableHeader>
							<TableHeader>Alertas</TableHeader>
							<TableHeader>Observaciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{preview.rows.map((row) => (
							<TableRow key={row.source_row}>
								<TableCell>{row.source_row}</TableCell>
								<TableCell><Badge color={row.source_action === "ALTA" ? "green" : "zinc"}>{row.source_action}</Badge></TableCell>
								<TableCell><Badge color={diagnosticColor(row.diagnostic_status)}>{row.diagnostic_status}</Badge></TableCell>
								<TableCell>{row.existing_account ? "Detectada" : "—"}</TableCell>
								<TableCell>{row.identity_conflict ? "Conflicto" : row.possible_duplicate ? "Revisar" : "—"}</TableCell>
								<TableCell><Flags flags={row.data_quality_flags} /></TableCell>
								<TableCell>{(row.notes || []).join("; ") || "—"}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</div>
	);
}

function Notice({ children }) {
	return <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{children}</div>;
}

function Metric({ label, value, color }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs text-zinc-500">{label}</Text>
			<p className={`mt-1 text-2xl font-semibold tabular-nums ${colorClass(color)}`}>{Number(value || 0).toLocaleString("es-MX")}</p>
		</div>
	);
}
function Flags({ flags = [] }) {
	if (!flags.length) return <span className="text-zinc-400">—</span>;
	return <div className="flex flex-wrap gap-1">{flags.slice(0, 3).map((flag) => <Badge key={flag} color="violet">{flag}</Badge>)}</div>;
}
function diagnosticColor(value) {
	if (value === "READY_TO_PRELOAD") return "green";
	if (value === "BLOCKED") return "red";
	if (value === "OTHER_EMAIL") return "orange";
	if (value?.includes("DUPLICATE")) return "violet";
	return "amber";
}
function colorClass(color) {
	return { green: "text-emerald-600", amber: "text-amber-600", orange: "text-orange-600", red: "text-red-600", violet: "text-violet-600" }[color] || "";
}
