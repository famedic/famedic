import { Link, useForm } from "@inertiajs/react";
import { useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";

const tabs = [
	{
		id: "bajas",
		label: "Bajas",
		hint: "Columnas: email, medical_attention_identifier (opcional), accion = baja",
	},
	{
		id: "altas",
		label: "Altas",
		hint: "Columnas: email, medical_attention_identifier (opcional), accion = alta",
	},
	{
		id: "validacion",
		label: "Validación",
		hint: "Columnas: email, medical_attention_identifier (opcional), accion = validacion",
	},
];

export default function MurguiaUpload({ successMessage }) {
	const [activeTab, setActiveTab] = useState("bajas");

	const form = useForm({
		file: null,
	});

	const submit = (e) => {
		e.preventDefault();
		form.post(route("admin.murguia.upload-excel"), {
			forceFormData: true,
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="Murguía — carga Excel">
			<div className="mx-auto max-w-3xl space-y-6">
				<Link
					href={route("admin.murguia-monitor.index")}
					className="text-sm text-blue-600 hover:underline"
				>
					← Volver al monitor
				</Link>

				<Heading>Carga masiva (Excel)</Heading>
				<Text className="text-zinc-600 dark:text-zinc-400">
					Primera fila: encabezados. Procesamiento en segundo plano; revise{" "}
					<Link href={route("admin.murguia.logs")} className="text-blue-600 hover:underline">
						logs de auditoría
					</Link>
					.
				</Text>

				{successMessage && (
					<div className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-200">
						{successMessage}
					</div>
				)}

				<div className="flex flex-wrap gap-2 border-b border-zinc-200 pb-2 dark:border-zinc-700">
					{tabs.map((t) => (
						<button
							key={t.id}
							type="button"
							onClick={() => setActiveTab(t.id)}
							className={`rounded-md px-3 py-2 text-sm font-medium ${
								activeTab === t.id
									? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker"
									: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
							}`}
						>
							{t.label}
						</button>
					))}
				</div>

				{tabs.map(
					(t) =>
						activeTab === t.id && (
							<div key={t.id} className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
								<Subheading level={3}>{t.label}</Subheading>
								<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{t.hint}</Text>
							</div>
						),
				)}

				<form onSubmit={submit} className="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
					<div>
						<Text className="mb-2 text-sm font-medium">Archivo (.xlsx, .xls, .csv)</Text>
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
					<Button type="submit" disabled={form.processing || !form.data.file}>
						{form.processing ? "Subiendo…" : "Subir y encolar"}
					</Button>
				</form>
			</div>
		</AdminLayout>
	);
}
