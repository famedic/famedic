import { useMemo, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

export default function PromptManagementModule({ data }) {
	const versions = data?.versions || [];
	const active = data?.active;
	const actions = data?.actions || [];
	const [selected, setSelected] = useState([]);
	const [compareOpen, setCompareOpen] = useState(false);

	const toggle = (key) => {
		setSelected((prev) => {
			if (prev.includes(key)) return prev.filter((k) => k !== key);
			if (prev.length >= 2) return [prev[1], key];
			return [...prev, key];
		});
	};

	const pair = useMemo(
		() => versions.filter((v) => selected.includes(v.key)),
		[versions, selected],
	);

	return (
		<div className="space-y-6">
			{active && (
				<section className="rounded-2xl border border-famedic-light/30 bg-famedic-light/5 px-5 py-4">
					<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Prompt activo
					</p>
					<div className="mt-2 flex flex-wrap items-center gap-2">
						<h3 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
							{active.label || active.key}
						</h3>
						<Badge color="emerald">v{active.version}</Badge>
						<Badge color="zinc">{active.model}</Badge>
					</div>
					<p className="mt-2 text-sm text-zinc-500">{active.notes}</p>
					<p className="mt-1 text-xs text-zinc-400">
						Autor · {active.author} · Estado · {active.status || "—"}
					</p>
				</section>
			)}

			<section className="space-y-3">
				<div className="flex flex-wrap items-center justify-between gap-2">
					<div>
						<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Versiones
						</h3>
						<p className="text-xs text-zinc-400">
							Solo lectura vía PromptProvider · sin modificar el provider
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						{actions.map((action) => (
							<Button
								key={action.id}
								outline
								disabled={!action.available || (action.id === "compare" && selected.length < 1)}
								className="!text-xs"
								title={action.note || undefined}
								onClick={() => {
									if (action.id === "compare") setCompareOpen(true);
								}}
							>
								{action.label}
							</Button>
						))}
					</div>
				</div>

				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>Seleccionar</TableHeader>
							<TableHeader>Versión</TableHeader>
							<TableHeader>Label</TableHeader>
							<TableHeader>Modelo</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Autor</TableHeader>
							<TableHeader>Notas</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{versions.length === 0 ? (
							<TableRow>
								<TableCell colSpan={7} className="text-zinc-400">
									No hay prompts en el catálogo.
								</TableCell>
							</TableRow>
						) : (
							versions.map((row) => (
								<TableRow key={row.key}>
									<TableCell>
										<input
											type="checkbox"
											checked={selected.includes(row.key)}
											onChange={() => toggle(row.key)}
											aria-label={`Seleccionar ${row.key}`}
										/>
									</TableCell>
									<TableCell className="font-medium">
										{row.key}
										<div className="text-[11px] text-zinc-400">v{row.version}</div>
									</TableCell>
									<TableCell>{row.label}</TableCell>
									<TableCell>{row.model}</TableCell>
									<TableCell>
										{row.active ? (
											<Badge color="emerald">Activo</Badge>
										) : (
											<Badge color="zinc">{row.status || "—"}</Badge>
										)}
									</TableCell>
									<TableCell className="text-xs">{row.author}</TableCell>
									<TableCell className="max-w-xs text-xs text-zinc-500">
										{row.notes}
									</TableCell>
								</TableRow>
							))
						)}
					</TableBody>
				</Table>
			</section>

			{compareOpen && (
				<section className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex items-center justify-between gap-2">
						<h3 className="text-sm font-semibold">Comparar versiones</h3>
						<Button plain className="!text-xs" onClick={() => setCompareOpen(false)}>
							Cerrar
						</Button>
					</div>
					{pair.length === 0 ? (
						<p className="mt-3 text-sm text-zinc-400">
							Selecciona una o dos versiones en la tabla.
						</p>
					) : (
						<div className="mt-4 grid gap-4 lg:grid-cols-2">
							{pair.map((p) => (
								<div
									key={p.key}
									className="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950"
								>
									<p className="text-sm font-semibold">
										{p.key} · v{p.version}
									</p>
									<p className="mt-1 text-xs text-zinc-400">{p.model}</p>
									<p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
										System prompt
									</p>
									<pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap text-xs text-zinc-600 dark:text-zinc-300">
										{p.system_prompt || p.system_prompt_preview || "—"}
									</pre>
									<p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
										User prompt
									</p>
									<pre className="mt-1 max-h-24 overflow-auto whitespace-pre-wrap text-xs text-zinc-600 dark:text-zinc-300">
										{p.user_prompt || p.user_prompt_preview || "—"}
									</pre>
								</div>
							))}
						</div>
					)}
					<p className="mt-4 text-xs text-zinc-400">
						Restaurar / Duplicar / Probar no escriben en PromptProvider. Usa
						Configuración IA o el Asistente para operar el prompt activo.
					</p>
				</section>
			)}
		</div>
	);
}
