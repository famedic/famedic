import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { ArrowDownTrayIcon, ArrowPathIcon } from "@heroicons/react/16/solid";
export default function LogsGeneral({
	logLines,
	logExists,
	logSize,
	logPath,
	linesRequested,
}) {
	const { get, data, setData, processing } = useForm({
		lines: linesRequested || 500,
	});

	const refresh = (e) => {
		e?.preventDefault();
		get(route("admin.logs-general.manage"), {
			data: { lines: data.lines },
			preserveState: true,
		});
	};

	const formatSize = (bytes) => {
		if (bytes < 1024) return `${bytes} B`;
		if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
		return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
	};

	return (
		<AdminLayout title="Logs generales">
			<div className="space-y-4">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Logs generales</Heading>
					<div className="flex items-center gap-2">
						<form onSubmit={refresh} className="flex items-center gap-2">
							<label className="text-sm text-zinc-500">
								Líneas:
							</label>
							<input
								type="number"
								min={100}
								max={5000}
								step={100}
								value={data.lines}
								onChange={(e) =>
									setData("lines", parseInt(e.target.value, 10) || 500)
								}
								className="w-24 rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
							/>
							<Button type="submit" outline disabled={processing}>
								<ArrowPathIcon className="size-4" />
								Actualizar
							</Button>
						</form>
						{logExists && (
							<Button href={route("admin.logs-general.download")}>
								<ArrowDownTrayIcon className="size-4" />
								Descargar log
							</Button>
						)}
					</div>
				</div>

				{logExists && (
					<p className="text-sm text-zinc-500">
						Archivo: <code className="rounded bg-zinc-100 px-1 dark:bg-zinc-800">{logPath}</code>
						{" · "}
						Tamaño: {formatSize(logSize)}
					</p>
				)}

				{!logExists ? (
					<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
						No se encontró el archivo de log en la ruta configurada.
					</div>
				) : logLines.length === 0 ? (
					<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
						El archivo de log está vacío.
					</div>
				) : (
					<pre className="max-h-[70vh] overflow-auto rounded-lg border border-zinc-200 bg-zinc-900 p-4 text-left text-xs text-zinc-100 dark:border-zinc-700">
						{logLines.map(({ number, text }) => (
							<div key={number} className="flex gap-3">
								<span className="select-none text-zinc-500">{number}</span>
								<span className="break-all">{text || " "}</span>
							</div>
						))}
					</pre>
				)}
			</div>
		</AdminLayout>
	);
}
