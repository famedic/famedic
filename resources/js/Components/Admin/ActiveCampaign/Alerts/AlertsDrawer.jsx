import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const PRIORITY = {
	critica: "red",
	alta: "orange",
	media: "amber",
	baja: "sky",
};

const STATUS = {
	nueva: "blue",
	vista: "zinc",
	en_proceso: "amber",
	resuelta: "emerald",
	ignorada: "zinc",
};

export default function AlertsDrawer({ open, alert = null, onClose }) {
	const { alertDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const alertIdRef = useRef(alert?.id ?? null);

	openRef.current = open;
	alertIdRef.current = alert?.id ?? null;

	const detailReady =
		alertDetail?.id && alert?.id && alertDetail.id === alert.id;
	const loading = Boolean(open && alert?.id && !detailReady);
	const detail = detailReady ? alertDetail : null;

	useEffect(() => {
		if (!open || !alert?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === alert.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = alert.id;
			return;
		}
		if (requestedIdRef.current === alert.id) {
			return;
		}

		requestedIdRef.current = alert.id;
		const gen = ++requestGenRef.current;
		const alertId = alert.id;

		router.reload({
			only: ["alertDetail"],
			data: { alert_id: alertId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					alertIdRef.current !== alertId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, alert?.id, detailReady]);

	const setStatus = (action) => {
		if (!alert?.id) return;
		requestedIdRef.current = null;
		router.reload({
			only: ["alertDetail"],
			data: { alert_id: alert.id, alert_action: action },
			preserveState: true,
			preserveScroll: true,
		});
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de alerta
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{alert?.title || "Alerta"}
							</Headless.DialogTitle>
							{alert ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={PRIORITY[alert.priority] || "zinc"}>
										{alert.priority_label}
									</Badge>
									<Badge color={STATUS[alert.status] || "zinc"}>
										{alert.status_label}
									</Badge>
									<AnalyticsTruthBadge truth={alert.truth} />
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
										Descripción completa
									</h3>
									<Text className="text-sm">
										{detail.description_full || detail.description}
									</Text>
								</section>

								<section className="grid gap-3 sm:grid-cols-2">
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Origen
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.origin_detail || detail.origin}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Módulo relacionado
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.related_module || detail.module}
										</p>
									</div>
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
											Paciente
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.patient || "—"}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Responsable
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.owner}{" "}
											<AnalyticsTruthBadge
												truth={detail.owner_truth || "proximamente"}
											/>
										</p>
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Impacto
									</h3>
									<Text className="text-sm">{detail.impact}</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Acciones sugeridas
									</h3>
									<ul className="list-inside list-disc space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
										{(detail.suggested_actions || []).map((action) => (
											<li key={action}>{action}</li>
										))}
									</ul>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Historial
									</h3>
									<ul className="space-y-2">
										{(detail.history || []).map((h, i) => (
											<li
												key={`${h.label}-${i}`}
												className="flex items-start justify-between gap-2 text-sm"
											>
												<span>
													{h.label}: {h.at}
												</span>
												<AnalyticsTruthBadge truth={h.truth || "proxy"} />
											</li>
										))}
									</ul>
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

								<section className="space-y-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Cambiar estado (sesión)
									</h3>
									<div className="flex flex-wrap gap-2">
										<Button outline onClick={() => setStatus("en_proceso")}>
											En proceso
										</Button>
										<Button outline onClick={() => setStatus("resuelta")}>
											Resuelta
										</Button>
										<Button outline onClick={() => setStatus("ignorada")}>
											Ignorada
										</Button>
									</div>
								</section>
							</>
						) : (
							<Text className="text-sm text-zinc-500">
								Selecciona una alerta para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
