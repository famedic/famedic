import { useEffect, useState } from "react";
import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Select } from "@/Components/Catalyst/select";

export default function ManualSearchDrawer({
	open,
	onClose,
	target,
	onSelect,
}) {
	const [q, setQ] = useState("");
	const [type, setType] = useState("all");
	const [loading, setLoading] = useState(false);
	const [results, setResults] = useState([]);
	const [error, setError] = useState(null);

	useEffect(() => {
		if (!open || !target) return;
		setQ(target.detected_name || "");
		setType("laboratory");
		setResults([]);
		setError(null);
	}, [open, target]);

	const search = async () => {
		if (!q.trim()) return;
		setLoading(true);
		setError(null);
		try {
			const url = route("admin.clinical-interpreter.catalog-search", {
				q: q.trim(),
				type,
			});
			const res = await fetch(url, {
				headers: {
					Accept: "application/json",
					"X-Requested-With": "XMLHttpRequest",
				},
				credentials: "same-origin",
			});
			if (!res.ok) throw new Error("Error de búsqueda");
			const data = await res.json();
			setResults(data.results || []);
		} catch (e) {
			setError(e.message || "No se pudo buscar");
			setResults([]);
		} finally {
			setLoading(false);
		}
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Búsqueda manual
							</p>
							<Headless.DialogTitle className="text-lg font-semibold text-zinc-950 dark:text-white">
								Catálogo Famedic
							</Headless.DialogTitle>
							{target && (
								<p className="mt-1 text-xs text-zinc-500">
									Detectado: {target.detected_name}
								</p>
							)}
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800"
						>
							<XMarkIcon className="size-5" />
						</button>
					</div>

					<div className="space-y-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<Field>
							<Label>Consulta</Label>
							<Input value={q} onChange={(e) => setQ(e.target.value)} />
						</Field>
						<Field>
							<Label>Tipo</Label>
							<Select value={type} onChange={(e) => setType(e.target.value)}>
								<option value="laboratory">Estudios de laboratorio</option>
							</Select>
						</Field>
						<Button onClick={search} disabled={loading}>
							{loading ? "Buscando…" : "Buscar"}
						</Button>
						{error && <p className="text-xs text-red-600">{error}</p>}
					</div>

					<div className="flex-1 space-y-2 overflow-y-auto px-5 py-4">
						{results.length === 0 && !loading && (
							<p className="text-sm text-zinc-500">
								Sin resultados. Prueba otra consulta.
							</p>
						)}
						{results.map((item) => (
							<button
								key={item.id}
								type="button"
								onClick={() => onSelect(target, item)}
								className="w-full rounded-lg border border-zinc-200 p-3 text-left transition hover:border-famedic-light dark:border-zinc-700"
							>
								<div className="flex flex-wrap items-center gap-1.5">
									<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{item.name}
									</p>
									<Badge color="sky" className="!text-[10px]">
										{item.similarity}%
									</Badge>
									<Badge
										color={item.available ? "emerald" : "red"}
										className="!text-[10px]"
									>
										{item.available ? "Disponible" : "No disponible"}
									</Badge>
								</div>
								<p className="mt-0.5 text-xs text-zinc-400">
									{[
										item.code || item.sku,
										item.laboratory || item.brand,
										item.price,
										item.delivery_time,
									]
										.filter(Boolean)
										.join(" · ")}
								</p>
							</button>
						))}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
