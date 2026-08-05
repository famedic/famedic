import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";

export default function JsonOcrDrawer({
	open,
	onClose,
	interpretation,
	metrics = null,
	rawJson = null,
}) {
	const ocr =
		interpretation?.ocr_text ||
		"(Sin capa OCR dedicada · extracción vía OpenAI Vision)";
	const jsonPayload = rawJson || interpretation?.ai_json || {};
	const json = JSON.stringify(jsonPayload, null, 2);

	const copy = async (text) => {
		try {
			await navigator.clipboard.writeText(text);
		} catch {
			// ignore
		}
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Transparencia
							</p>
							<Headless.DialogTitle className="text-lg font-semibold text-zinc-950 dark:text-white">
								OCR ↔ JSON
							</Headless.DialogTitle>
							<div className="mt-1 flex flex-wrap gap-1.5">
								<Badge color="zinc">Documento</Badge>
								<Badge color="famedic">JSON IA</Badge>
								{metrics?.prompt_version && (
									<Badge color="sky">v{metrics.prompt_version}</Badge>
								)}
							</div>
							{metrics && (
								<p className="mt-2 text-[11px] text-zinc-400">
									{[
										metrics.model,
										metrics.duration_ms != null
											? `${metrics.duration_ms} ms`
											: null,
										metrics.total_tokens != null
											? `${metrics.total_tokens} tokens`
											: null,
										metrics.estimated_cost_usd != null
											? `~$${metrics.estimated_cost_usd}`
											: null,
									]
										.filter(Boolean)
										.join(" · ")}
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

					<div className="grid flex-1 grid-cols-1 gap-0 overflow-hidden md:grid-cols-2">
						<div className="flex flex-col border-b border-zinc-200 md:border-b-0 md:border-r dark:border-zinc-700">
							<div className="flex items-center justify-between px-4 py-2">
								<p className="text-xs font-semibold text-zinc-500">
									Notas de extracción
								</p>
								<Button plain className="!text-xs" onClick={() => copy(ocr)}>
									Copiar
								</Button>
							</div>
							<pre className="flex-1 overflow-auto whitespace-pre-wrap bg-zinc-50 p-4 font-mono text-xs text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
								{ocr}
							</pre>
						</div>
						<div className="flex flex-col">
							<div className="flex items-center justify-between px-4 py-2">
								<p className="text-xs font-semibold text-zinc-500">
									JSON generado
								</p>
								<Button plain className="!text-xs" onClick={() => copy(json)}>
									Copiar
								</Button>
							</div>
							<pre className="flex-1 overflow-auto whitespace-pre-wrap bg-white p-4 font-mono text-xs text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
								{json}
							</pre>
						</div>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
