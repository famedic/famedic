import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function JourneyUserDrawer({ open, drawer, loading = false, onClose }) {
	const summary = drawer?.summary;

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-famedic-light">
								Customer Journey
							</p>
							<Headless.DialogTitle className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
								{summary?.name || (loading ? "Cargando…" : "Usuario")}
							</Headless.DialogTitle>
							{summary?.email ? (
								<Text className="mt-0.5 text-xs text-zinc-500">{summary.email}</Text>
							) : null}
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
						>
							<XMarkIcon className="size-5" />
						</button>
					</div>

					<div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{loading && !drawer ? (
							<p className="text-sm text-zinc-400">Cargando timeline…</p>
						) : null}

						{summary ? (
							<div className="grid grid-cols-2 gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
								<div>
									<p className="text-[11px] uppercase text-zinc-400">Etapa actual</p>
									<p className="text-sm font-medium">{summary.last_stage_label}</p>
								</div>
								<div>
									<p className="text-[11px] uppercase text-zinc-400">Detenido</p>
									<p className="text-sm font-medium">{summary.days_stalled} días</p>
								</div>
								<div>
									<p className="text-[11px] uppercase text-zinc-400">Lead score</p>
									<p className="text-sm font-medium">{summary.lead_score}</p>
								</div>
								<div>
									<p className="text-[11px] uppercase text-zinc-400">Probabilidad IA</p>
									<p className="text-sm font-medium">{summary.ai_probability}%</p>
								</div>
							</div>
						) : null}

						{drawer?.timeline?.length ? (
							<section>
								<h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">
									Timeline completo
								</h3>
								<ol className="space-y-0">
									{drawer.timeline.map((event, index) => (
										<li key={`${event.key}-${index}`} className="relative flex gap-3 pb-5">
											{index < drawer.timeline.length - 1 ? (
												<span className="absolute bottom-0 left-[7px] top-4 w-px bg-zinc-200 dark:bg-zinc-700" />
											) : null}
											<span className="relative z-10 mt-1 size-3.5 shrink-0 rounded-full bg-sky-500 ring-4 ring-white dark:ring-zinc-900" />
											<div>
												<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
													{event.label}
												</p>
												<p className="text-xs text-zinc-400">{event.at}</p>
												{event.detail ? (
													<p className="mt-0.5 text-xs text-zinc-500">{event.detail}</p>
												) : null}
											</div>
										</li>
									))}
								</ol>
							</section>
						) : null}

						{summary?.risk_segment ? (
							<Badge color="zinc">{summary.risk_segment}</Badge>
						) : null}
					</div>

					<div className="flex gap-2 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
						{summary?.show_url ? (
							<Button href={summary.show_url} className="flex-1">
								Abrir ficha
							</Button>
						) : null}
						<Button outline className="flex-1" onClick={onClose}>
							Cerrar
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
