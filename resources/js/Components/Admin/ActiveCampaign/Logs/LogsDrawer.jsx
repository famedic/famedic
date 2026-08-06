import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const LEVEL = {
	error: "red",
	warning: "amber",
	info: "sky",
};

export default function LogsDrawer({ open, log = null, onClose }) {
	const { logDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const logIdRef = useRef(log?.id ?? null);

	openRef.current = open;
	logIdRef.current = log?.id ?? null;

	const detailReady = logDetail?.id && log?.id && logDetail.id === log.id;
	const loading = Boolean(open && log?.id && !detailReady);
	const detail = detailReady ? logDetail : null;

	useEffect(() => {
		if (!open || !log?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === log.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = log.id;
			return;
		}
		if (requestedIdRef.current === log.id) {
			return;
		}

		requestedIdRef.current = log.id;
		const gen = ++requestGenRef.current;
		const logId = log.id;

		router.reload({
			only: ["logDetail"],
			data: { log_id: logId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					logIdRef.current !== logId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, log?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de log
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{log?.event || "Log"}
							</Headless.DialogTitle>
							{log ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={LEVEL[log.level] || "zinc"}>
										{log.level_label}
									</Badge>
									<Badge color="zinc">{log.status_label}</Badge>
									<AnalyticsTruthBadge truth={log.truth} />
								</div>
							) : null}
						</div>
						<Button plain onClick={onClose} aria-label="Cerrar">
							<XMarkIcon className="size-5" />
						</Button>
					</div>

					<div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{loading ? (
							<div className="space-y-3" aria-busy="true">
								{Array.from({ length: 5 }).map((_, i) => (
									<div
										key={i}
										className="h-16 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
									/>
								))}
							</div>
						) : detail ? (
							<>
								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Detalle
									</h3>
									<Text className="text-sm">
										{detail.detail || detail.description}
									</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Contexto
									</h3>
									<div className="grid gap-3 sm:grid-cols-2">
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Origen
											</p>
											<p className="mt-1 text-sm font-medium">{detail.origin}</p>
										</div>
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Módulo
											</p>
											<p className="mt-1 text-sm font-medium">{detail.module}</p>
										</div>
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Paciente
											</p>
											<p className="mt-1 text-sm font-medium">
												{detail.patient || "—"}
											</p>
										</div>
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Cuando
											</p>
											<p className="mt-1 text-sm font-medium">
												{detail.date} {detail.time}
											</p>
										</div>
										{Object.entries(detail.context || {}).map(([key, value]) => (
											<div key={key}>
												<p className="text-[11px] uppercase tracking-wide text-zinc-400">
													{key}
												</p>
												<p className="mt-1 break-all text-sm font-medium">
													{value === null || value === undefined
														? "—"
														: String(value)}
												</p>
											</div>
										))}
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Payload sanitizado
									</h3>
									{detail.payload_sanitized ? (
										<pre className="overflow-x-auto rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
											{JSON.stringify(detail.payload_sanitized, null, 2)}
										</pre>
									) : (
										<Text className="text-sm text-zinc-500">No disponible</Text>
									)}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Stack resumido
									</h3>
									<pre className="whitespace-pre-wrap rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
										{detail.stack_summary || "No disponible"}
									</pre>
								</section>

								<section className="grid gap-3 sm:grid-cols-2">
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Evento relacionado
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.related_event}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Timeline relacionado
										</p>
										<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
											{detail.related_timeline}
										</p>
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Links rápidos
									</h3>
									<div className="flex flex-wrap gap-2">
										{(detail.quick_links || []).map((link) => (
											<Button
												key={`${link.label}-${link.href}`}
												href={link.href}
												outline
											>
												{link.label}
											</Button>
										))}
									</div>
								</section>
							</>
						) : (
							<Text className="text-sm text-zinc-500">
								Selecciona un log para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
