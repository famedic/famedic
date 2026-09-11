import { Link, useForm } from "@inertiajs/react";
import { ArrowLeftIcon, DocumentMagnifyingGlassIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function Create({ errors = {} }) {
	const form = useForm({
		source_file: null,
		murguia_file: null,
	});

	const submit = (event) => {
		event.preventDefault();
		form.post(route("admin.odessa.reconciliations.store"), {
			forceFormData: true,
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="Nueva conciliación ODESSA">
			<div className="mx-auto max-w-4xl space-y-6 text-zinc-900 dark:text-zinc-100">
				<Link
					href={route("admin.odessa.reconciliations.index")}
					className="inline-flex items-center gap-1 text-sm text-famedic-dark hover:underline dark:text-famedic-lime"
				>
					<ArrowLeftIcon className="size-4" />
					Volver al historial
				</Link>

				<div>
					<Heading>Nueva conciliación ODESSA</Heading>
					<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
						El resultado quedará persistido como snapshot histórico. No se
						modifican usuarios, clientes, cuentas ODESSA, membresías ni Murguía.
					</Text>
				</div>

				<form
					onSubmit={submit}
					className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="grid gap-5 lg:grid-cols-2">
						<FileField
							label="Reporte de colaboradores"
							required
							error={form.errors.source_file || errors.source_file}
							onChange={(file) => form.setData("source_file", file)}
						/>
						<FileField
							label="Reporte Murguía / padrón mensual"
							error={form.errors.murguia_file || errors.murguia_file}
							onChange={(file) => form.setData("murguia_file", file)}
						/>
					</div>

					<div className="mt-6 flex justify-end">
						<Button type="submit" disabled={form.processing || !form.data.source_file}>
							<DocumentMagnifyingGlassIcon data-slot="icon" />
							{form.processing ? "Analizando y guardando…" : "Analizar conciliación"}
						</Button>
					</div>
				</form>
			</div>
		</AdminLayout>
	);
}

function FileField({ label, required = false, error, onChange }) {
	return (
		<div>
			<Text className="mb-2 text-sm font-medium">
				{label} {required ? <span className="text-red-600">*</span> : null}
			</Text>
			<input
				type="file"
				accept=".xlsx,.xls"
				onChange={(event) => onChange(event.target.files?.[0] || null)}
				className="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-100"
			/>
			{error ? <Text className="mt-1 text-sm text-red-600">{error}</Text> : null}
		</div>
	);
}
