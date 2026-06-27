import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function ProviderUploadPanel() {
	const form = useForm({ file: null });

	const submit = (e) => {
		e.preventDefault();
		form.post(route("admin.murguia-reconciliation.upload"), {
			forceFormData: true,
			preserveScroll: true,
		});
	};

	return (
		<form
			onSubmit={submit}
			className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"
		>
			<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
				Cargar archivo proveedor (solo preview)
			</Text>
			<Text className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
				Formatos: .xlsx, .xls, .csv. Columnas reconocidas: noCredito,
				email, nombre, estatus, tipo membresía, vigencia. No ejecuta altas ni
				bajas — solo compara contra BD local.
			</Text>

			<div className="mt-4">
				<input
					type="file"
					accept=".xlsx,.xls,.csv"
					onChange={(e) => form.setData("file", e.target.files?.[0] || null)}
					className="block w-full text-sm"
				/>
				{form.errors.file && (
					<Text className="mt-1 text-sm text-red-600">{form.errors.file}</Text>
				)}
			</div>

			<div className="mt-4 flex flex-wrap gap-2">
				<Button type="submit" disabled={form.processing || !form.data.file}>
					{form.processing ? "Analizando…" : "Analizar conciliación"}
				</Button>
			</div>
		</form>
	);
}

export function ReconciliationSummary({ summary, meta }) {
	if (!summary) return null;

	const items = [
		{ key: "matched_ok", label: "Coincidencias OK", color: "text-emerald-600" },
		{ key: "provider_only", label: "Solo proveedor", color: "text-amber-600" },
		{ key: "local_only", label: "Solo BD local", color: "text-amber-600" },
		{
			key: "provider_active_local_expired",
			label: "Activo proveedor / vencido local",
			color: "text-red-600",
		},
		{
			key: "local_active_provider_inactive",
			label: "Activo local / inactivo proveedor",
			color: "text-red-600",
		},
		{
			key: "duplicate_credito_in_file",
			label: "noCredito dup. archivo",
			color: "text-orange-600",
		},
		{
			key: "duplicate_email_in_file",
			label: "Email dup. archivo",
			color: "text-orange-600",
		},
		{ key: "name_mismatch", label: "Nombre distinto", color: "text-violet-600" },
		{
			key: "membership_type_mismatch",
			label: "Membresía distinta",
			color: "text-violet-600",
		},
	];

	return (
		<div className="space-y-4">
			{meta && (
				<div className="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
					<p>
						<span className="font-medium">Archivo:</span> {meta.filename}
					</p>
					<p className="text-zinc-600 dark:text-zinc-400">
						Filas proveedor: {meta.provider_rows?.toLocaleString("es-MX")} ·
						Asegurados locales: {meta.local_insured_count?.toLocaleString("es-MX")}
						{meta.detected_header_row
							? ` · Encabezados detectados en fila ${meta.detected_header_row}`
							: ""}
						{meta.truncated ? " · Resultado truncado (máx. 5000 filas)" : ""}
					</p>
				</div>
			)}

			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
				{items.map((item) => (
					<div
						key={item.key}
						className="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<Text className="text-xs text-zinc-500">{item.label}</Text>
						<p className={`mt-1 text-xl font-semibold tabular-nums ${item.color}`}>
							{Number(summary[item.key] ?? 0).toLocaleString("es-MX")}
						</p>
					</div>
				))}
			</div>
		</div>
	);
}

export function ReconciliationIssueFilter({ issueTypes, issueFilter }) {
	const applyFilter = (key) => {
		router.get(
			route("admin.murguia-reconciliation.index"),
			key ? { issue_type: key } : {},
			{ preserveState: true, replace: true },
		);
	};

	return (
		<div className="flex flex-wrap gap-2">
			{issueTypes.map((type) => (
				<Button
					key={type.key || "all"}
					type="button"
					outline={issueFilter !== type.key}
					onClick={() => applyFilter(type.key)}
					className="text-xs"
				>
					{type.label}
				</Button>
			))}
		</div>
	);
}
