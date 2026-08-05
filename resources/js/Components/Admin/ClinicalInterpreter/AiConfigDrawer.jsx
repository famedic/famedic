import { useEffect, useState } from "react";
import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label } from "@/Components/Catalyst/fieldset";

export default function AiConfigDrawer({
	open,
	onClose,
	config,
	promptCatalog = [],
}) {
	const [selectedKey, setSelectedKey] = useState(null);

	useEffect(() => {
		if (open) {
			setSelectedKey(config?.version ? null : null);
		}
	}, [open, config]);

	const active =
		promptCatalog.find((p) => p.version === selectedKey) ||
		promptCatalog.find((p) => p.version === config?.version) ||
		config;

	const statusColor =
		active?.status === "production"
			? "emerald"
			: active?.status === "experimental"
				? "amber"
				: "zinc";

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Configuración IA
							</p>
							<Headless.DialogTitle className="text-lg font-semibold text-zinc-950 dark:text-white">
								Prompt Provider
							</Headless.DialogTitle>
							<div className="flex flex-wrap gap-1.5">
								<Badge color="famedic">Solo lectura</Badge>
								<Badge color={statusColor}>
									{active?.status === "production"
										? "Producción"
										: active?.status === "experimental"
											? "Experimental"
											: active?.status || "—"}
								</Badge>
								<Badge color="zinc">v{active?.version || "—"}</Badge>
							</div>
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800"
						>
							<XMarkIcon className="size-5" />
						</button>
					</div>

					<div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{promptCatalog.length > 1 && (
							<Field>
								<Label>Versión</Label>
								<select
									className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
									value={active?.version || ""}
									onChange={(e) => setSelectedKey(e.target.value)}
								>
									{promptCatalog.map((p) => (
										<option key={p.version} value={p.version}>
											{p.version} · {p.status} · {p.label}
										</option>
									))}
								</select>
							</Field>
						)}

						<Field>
							<Label>Modelo</Label>
							<Input value={active?.model || ""} readOnly />
						</Field>
						<div className="grid grid-cols-3 gap-3">
							<Field>
								<Label>Temperatura</Label>
								<Input value={active?.temperature ?? ""} readOnly />
							</Field>
							<Field>
								<Label>Top P</Label>
								<Input value={active?.top_p ?? ""} readOnly />
							</Field>
							<Field>
								<Label>Max Tokens</Label>
								<Input value={active?.max_tokens ?? ""} readOnly />
							</Field>
						</div>
						<Field>
							<Label>Estado</Label>
							<Input
								value={
									active?.status === "production"
										? "Producción"
										: active?.status === "experimental"
											? "Experimental"
											: active?.status || ""
								}
								readOnly
							/>
						</Field>
						<Field>
							<Label>Prompt del sistema</Label>
							<Textarea
								rows={8}
								value={active?.system_prompt || ""}
								readOnly
							/>
						</Field>
						<Field>
							<Label>Prompt del usuario</Label>
							<Textarea rows={4} value={active?.user_prompt || ""} readOnly />
						</Field>

						<Text className="!text-xs text-zinc-400">
							Los prompts se cargan desde PromptRepository (archivos
							versionados). Edición/persistencia llegará en un sprint posterior.
							OpenAI solo interpreta; Famedic hace el matching.
						</Text>
					</div>

					<div className="flex flex-wrap gap-2 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<Button outline onClick={onClose}>
							Cerrar
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
