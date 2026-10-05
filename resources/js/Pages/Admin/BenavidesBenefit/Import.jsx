import { useEffect } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";

function Metric({ label, value, tone = "zinc" }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<div className="mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">
				{Number(value || 0).toLocaleString("es-MX")}
			</div>
			<Badge color={tone} className="mt-2">
				{label}
			</Badge>
		</div>
	);
}

function Examples({ title, rows }) {
	if (!rows?.length) return null;

	return (
		<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
			<Text className="font-semibold text-zinc-950 dark:text-white">{title}</Text>
			<ul className="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
				{rows.map((row, index) => (
					<li key={index}>
						Fila {row.row || "—"} {row.code ? `· ${row.code}` : ""}{" "}
						{row.user_id ? `· Usuario #${row.user_id}` : ""}
					</li>
				))}
			</ul>
		</div>
	);
}

export default function Import({ preview, result }) {
	const previewForm = useForm({ source_file: null });
	const confirmForm = useForm({ import_id: preview?.import_id || "" });

	useEffect(() => {
		confirmForm.setData("import_id", preview?.import_id || "");
	}, [preview?.import_id]);

	const uploadPreview = (event) => {
		event.preventDefault();
		previewForm.post(route("admin.benavides-benefit.import.preview"), {
			forceFormData: true,
		});
	};

	const confirmImport = (event) => {
		event.preventDefault();
		confirmForm.post(route("admin.benavides-benefit.import.confirm"));
	};

	const summary = preview?.summary;

	return (
		<AdminLayout title="Importar códigos Benavides">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Importar códigos Benavides</Heading>
						<Text className="mt-2 max-w-3xl">
							Sube un CSV o XLSX, revisa el preview y confirma explícitamente la importación.
						</Text>
					</div>
					<Button href={route("admin.benavides-benefit.index")} outline>
						Volver al inventario
					</Button>
				</div>

				<form onSubmit={uploadPreview} className="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
					<Text className="font-semibold text-zinc-950 dark:text-white">Paso 1 · Seleccionar archivo</Text>
					<Text className="mt-2">
						Formato recomendado: una columna <span className="font-mono">code</span> con códigos como texto.
					</Text>
					<input
						type="file"
						accept=".csv,.xlsx"
						className="mt-4 block w-full text-sm"
						onChange={(event) => previewForm.setData("source_file", event.target.files?.[0] || null)}
					/>
					{previewForm.errors.source_file ? (
						<Text className="mt-2 text-sm text-red-600">{previewForm.errors.source_file}</Text>
					) : null}
					<Button type="submit" className="mt-4" disabled={previewForm.processing || !previewForm.data.source_file}>
						{previewForm.processing ? "Procesando..." : "Generar preview"}
					</Button>
				</form>

				{summary ? (
					<section className="space-y-4 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
						<div>
							<Text className="font-semibold text-zinc-950 dark:text-white">Paso 2 · Preview</Text>
							<Text className="mt-1">{preview.filename}</Text>
							<Text className="mt-1 text-sm text-zinc-500">{preview.format_note}</Text>
						</div>
						<div className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
							<Metric label="Filas" value={summary.total_rows} />
							<Metric label="Válidas" value={summary.valid_rows} tone="lime" />
							<Metric label="Nuevas" value={summary.importable_rows} tone="green" />
							<Metric label="Duplicadas" value={summary.duplicate_database_rows} tone="amber" />
							<Metric label="Conflictos" value={summary.assigned_conflict_rows} tone="red" />
							<Metric label="Vacías" value={summary.empty_rows} />
						</div>
						<div className="grid gap-4 lg:grid-cols-2">
							<Examples title="Vacíos" rows={summary.examples?.empty} />
							<Examples title="Duplicados en archivo" rows={summary.examples?.duplicate_file} />
							<Examples title="Duplicados en FAMEDIC" rows={summary.examples?.duplicate_database} />
							<Examples title="Ya asignados" rows={summary.examples?.assigned_conflict} />
						</div>
						<form onSubmit={confirmImport} className="border-t border-zinc-200 pt-4 dark:border-zinc-800">
							<Text className="font-semibold text-zinc-950 dark:text-white">Paso 3 · Confirmar</Text>
							<Text className="mt-1">Esta acción no modifica códigos ya asignados.</Text>
							{confirmForm.errors.import_id ? (
								<Text className="mt-2 text-sm text-red-600">{confirmForm.errors.import_id}</Text>
							) : null}
							<Button type="submit" className="mt-4" disabled={confirmForm.processing || !summary.importable_rows}>
								{confirmForm.processing ? "Importando..." : "Importar códigos válidos"}
							</Button>
						</form>
					</section>
				) : null}

				{result ? (
					<section className="rounded-lg border border-green-200 bg-green-50 p-5 dark:border-green-500/30 dark:bg-green-500/10">
						<Text className="font-semibold text-green-900 dark:text-green-100">Resultado final</Text>
						<Text className="mt-2">
							{Number(result.imported_rows || 0).toLocaleString("es-MX")} importados ·{" "}
							{Number(result.rejected_rows || 0).toLocaleString("es-MX")} omitidos ·{" "}
							{Number(result.duplicate_database_rows || 0).toLocaleString("es-MX")} duplicados ·{" "}
							{Number(result.assigned_conflict_rows || 0).toLocaleString("es-MX")} conflictos.
						</Text>
						<Button href={route("admin.benavides-benefit.index")} className="mt-4">
							Volver al inventario
						</Button>
					</section>
				) : null}
			</div>
		</AdminLayout>
	);
}
