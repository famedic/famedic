import * as Headless from "@headlessui/react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { XMarkIcon } from "@heroicons/react/16/solid";

function Row({ label, children }) {
	return (
		<div className="grid grid-cols-[120px_1fr] gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-800">
			<p className="text-xs uppercase tracking-wide text-zinc-400">{label}</p>
			<div className="min-w-0 break-words text-zinc-800 dark:text-zinc-100">
				{children}
			</div>
		</div>
	);
}

export default function AutomationEventDrawer({ event, open, onClose }) {
	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<Headless.DialogTitle className="text-base font-semibold text-zinc-900 dark:text-zinc-50">
								Detalle de automatización
							</Headless.DialogTitle>
							<p className="mt-1 text-xs text-zinc-500">
								Solo lectura · auditoría
							</p>
						</div>
						<Button plain onClick={onClose}>
							<XMarkIcon />
						</Button>
					</div>

					<div className="flex-1 overflow-y-auto px-5 py-4">
						{!event ? (
							<Text className="text-sm text-zinc-500">Sin selección.</Text>
						) : (
							<div>
								<Row label="Hora">{event.occurred_at || "—"}</Row>
								<Row label="Automation">{event.automation || "—"}</Row>
								<Row label="Driver">{event.driver || "—"}</Row>
								<Row label="Resultado">
									<Badge color="zinc">{event.result || "—"}</Badge>
								</Row>
								<Row label="Duración">
									{event.duration_ms != null
										? `${event.duration_ms} ms`
										: "—"}
								</Row>
								<Row label="Retryable">
									{event.retryable == null
										? "—"
										: event.retryable
											? "Sí"
											: "No"}
								</Row>
								<Row label="Canal">{event.channel || "—"}</Row>
								<Row label="Operación">{event.operation || "—"}</Row>
								<Row label="Referencia">{event.reference || "—"}</Row>
								<Row label="Fuente">{event.source || "—"}</Row>
								{event.meta ? (
									<Row label="Meta">
										<pre className="overflow-x-auto rounded-lg bg-zinc-50 p-2 text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
											{JSON.stringify(event.meta, null, 2)}
										</pre>
									</Row>
								) : null}
							</div>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
