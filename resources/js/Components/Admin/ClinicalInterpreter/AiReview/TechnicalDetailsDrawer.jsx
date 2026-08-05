import { useState } from "react";
import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { formatDurationMs } from "./confidenceHelpers";

const TABS = [
	{ id: "summary", label: "Resumen" },
	{ id: "ocr", label: "OCR" },
	{ id: "json", label: "JSON" },
	{ id: "prompt", label: "Prompt utilizado" },
	{ id: "model", label: "Modelo" },
	{ id: "cost", label: "Costo estimado" },
	{ id: "time", label: "Tiempo" },
];

/**
 * Drawer técnico — fuera del flujo principal. Solo soporte / admins.
 */
export default function TechnicalDetailsDrawer({
	open,
	onClose,
	interpretPayload = null,
	order = null,
}) {
	const [tab, setTab] = useState("summary");

	const interpretation =
		interpretPayload?.interpretation || order?.interpretation || null;
	const metrics =
		interpretPayload?.interpretation_metrics ||
		order?.interpretation?.raw_metrics ||
		{};
	const model =
		metrics.model || interpretation?.model || null;
	const promptVersion =
		metrics.prompt_version || interpretation?.prompt_version || null;
	const promptKey =
		metrics.prompt_key || interpretation?.prompt_key || null;
	const durationMs =
		metrics.duration_ms ?? interpretation?.raw_metrics?.duration_ms ?? null;
	const cost =
		metrics.estimated_cost_usd ??
		interpretation?.raw_metrics?.estimated_cost_usd ??
		null;

	const ocr =
		interpretation?.ocr_text ||
		"(Sin capa OCR dedicada · extracción vía OpenAI Vision)";
	const jsonPayload =
		interpretPayload?.vision?.raw_json ||
		interpretation?.ai_json ||
		{};
	const json = JSON.stringify(jsonPayload, null, 2);

	const copy = async (text) => {
		try {
			await navigator.clipboard.writeText(text);
		} catch {
			// ignore
		}
	};

	const empty = !interpretPayload && !order?.interpretation;

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-zinc-950/30 transition duration-200 data-closed:opacity-0 dark:bg-zinc-950/50"
			/>
			<div className="fixed inset-0 flex justify-end sm:p-3">
				<Headless.DialogPanel
					transition
					className="flex h-full w-full max-w-lg flex-col border-l border-zinc-200 bg-zinc-50 shadow-lg transition duration-300 ease-out data-closed:translate-x-8 data-closed:opacity-0 dark:border-zinc-700 dark:bg-zinc-950 sm:rounded-l-xl sm:border"
				>
					<header className="border-b border-zinc-200 bg-zinc-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-950">
						<div className="flex items-start justify-between gap-3">
							<div className="space-y-2">
								<p className="inline-flex rounded-md border border-zinc-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
									Solo soporte técnico
								</p>
								<Headless.DialogTitle className="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
									Detalles técnicos
								</Headless.DialogTitle>
								<Text className="!text-xs !leading-relaxed text-zinc-500">
									No forma parte del flujo operativo. OCR, JSON y métricas para
									diagnóstico.
								</Text>
							</div>
							<button
								type="button"
								onClick={onClose}
								aria-label="Cerrar detalles técnicos"
								className="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
							>
								<XMarkIcon className="size-5" />
							</button>
						</div>
					</header>

					<div className="flex gap-1 overflow-x-auto border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
						{TABS.map((t) => (
							<button
								key={t.id}
								type="button"
								onClick={() => setTab(t.id)}
								className={`shrink-0 rounded-lg px-2.5 py-1.5 text-[11px] font-medium transition ${
									tab === t.id
										? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
										: "text-zinc-500 hover:bg-zinc-200/70 dark:hover:bg-zinc-800"
								}`}
							>
								{t.label}
							</button>
						))}
					</div>

					<div className="flex-1 overflow-y-auto px-5 py-5">
						{empty ? (
							<p className="text-sm text-zinc-400">
								Se llenará cuando haya una interpretación activa.
							</p>
						) : (
							<>
								{tab === "summary" && (
									<dl className="space-y-3 text-sm">
										<div>
											<dt className="text-xs text-zinc-400">Modelo</dt>
											<dd className="font-medium text-zinc-800 dark:text-zinc-100">
												{model || "—"}
											</dd>
										</div>
										<div>
											<dt className="text-xs text-zinc-400">Prompt</dt>
											<dd className="font-medium text-zinc-800 dark:text-zinc-100">
												{[promptKey, promptVersion ? `v${promptVersion}` : null]
													.filter(Boolean)
													.join(" · ") || "—"}
											</dd>
										</div>
										<div>
											<dt className="text-xs text-zinc-400">Tiempo</dt>
											<dd className="font-medium text-zinc-800 dark:text-zinc-100">
												{formatDurationMs(durationMs) || "—"}
											</dd>
										</div>
										<div>
											<dt className="text-xs text-zinc-400">Costo estimado</dt>
											<dd className="font-medium text-zinc-800 dark:text-zinc-100">
												{cost != null ? `~$${cost} USD` : "—"}
											</dd>
										</div>
									</dl>
								)}

								{tab === "ocr" && (
									<div className="space-y-2">
										<div className="flex justify-end">
											<Button plain className="!text-xs" onClick={() => copy(ocr)}>
												Copiar
											</Button>
										</div>
										<pre className="whitespace-pre-wrap rounded-lg border border-zinc-200 bg-white p-3 font-mono text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
											{ocr}
										</pre>
									</div>
								)}

								{tab === "json" && (
									<div className="space-y-2">
										<div className="flex justify-end">
											<Button
												plain
												className="!text-xs"
												onClick={() => copy(json)}
											>
												Copiar
											</Button>
										</div>
										<pre className="overflow-x-auto whitespace-pre-wrap rounded-lg border border-zinc-200 bg-white p-3 font-mono text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
											{json}
										</pre>
									</div>
								)}

								{tab === "prompt" && (
									<dl className="space-y-3 text-sm">
										<div>
											<dt className="text-xs text-zinc-400">Clave</dt>
											<dd className="font-medium">{promptKey || "—"}</dd>
										</div>
										<div>
											<dt className="text-xs text-zinc-400">Versión</dt>
											<dd className="font-medium">
												{promptVersion ? `v${promptVersion}` : "—"}
											</dd>
										</div>
										<div>
											<dt className="text-xs text-zinc-400">Estado</dt>
											<dd className="font-medium">
												{metrics.prompt_status || "—"}
											</dd>
										</div>
									</dl>
								)}

								{tab === "model" && (
									<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
										{model || "—"}
										{metrics.provider ? (
											<span className="mt-1 block text-xs font-normal text-zinc-400">
												Proveedor · {metrics.provider}
											</span>
										) : null}
									</p>
								)}

								{tab === "cost" && (
									<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
										{cost != null
											? `Costo estimado · ~$${cost} USD`
											: "Costo no disponible"}
									</p>
								)}

								{tab === "time" && (
									<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
										{formatDurationMs(durationMs) || "Tiempo no disponible"}
									</p>
								)}
							</>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
