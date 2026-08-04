import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

const BADGE = {
	sky: "sky",
	blue: "blue",
	emerald: "emerald",
	purple: "violet",
	amber: "amber",
	orange: "orange",
	red: "red",
	zinc: "zinc",
};

export default function EventDrawer({ open, event = null, onClose }) {
	const { eventDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const eventIdRef = useRef(event?.id ?? null);

	openRef.current = open;
	eventIdRef.current = event?.id ?? null;

	const detailReady =
		eventDetail?.id && event?.id && eventDetail.id === event.id;
	const loading = Boolean(open && event?.id && !detailReady);
	const detail = detailReady ? eventDetail : null;

	useEffect(() => {
		if (!open || !event?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === event.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = event.id;
			return;
		}
		if (requestedIdRef.current === event.id) {
			return;
		}

		requestedIdRef.current = event.id;
		const gen = ++requestGenRef.current;
		const eventId = event.id;

		router.reload({
			only: ["eventDetail"],
			data: {
				event_id: eventId,
				event_contact_id: event.contact_id || "",
			},
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					eventIdRef.current !== eventId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, event?.id, event?.contact_id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle del evento
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{event?.type_label || "Evento"}
							</Headless.DialogTitle>
							{event ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={BADGE[event.color] || "zinc"}>
										{event.badge}
									</Badge>
									<Badge color="zinc">{event.status_label}</Badge>
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
										Descripción
									</h3>
									<Text className="text-sm">{detail.description}</Text>
								</section>

								<section className="grid gap-3 sm:grid-cols-2">
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Origen
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.source_label}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Estado
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.status_label}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Fecha
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.date} {detail.time}
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
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Modelo relacionado
									</h3>
									<pre className="overflow-x-auto rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
										{JSON.stringify(detail.related_model || {}, null, 2)}
									</pre>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Timeline
									</h3>
									<Text className="text-sm">
										{detail.timeline_note || "No disponible"}
									</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Payload
									</h3>
									{detail.payload ? (
										<pre className="max-h-64 overflow-auto rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
											{JSON.stringify(detail.payload, null, 2)}
										</pre>
									) : (
										<Text className="text-sm text-zinc-500">
											{detail.payload_label || "No disponible"}
										</Text>
									)}
									{detail.last_error ? (
										<div className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
											{detail.last_error}
										</div>
									) : null}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Acciones disponibles
									</h3>
									<div className="flex flex-wrap gap-2">
										{(detail.actions || []).map((action) =>
											action.enabled && action.href ? (
												<Button
													key={action.id}
													href={action.href}
													outline
												>
													{action.label}
												</Button>
											) : (
												<div key={action.id} className="space-y-1">
													<Button
														outline
														disabled
														title={action.hint || "No disponible"}
													>
														{action.label}
													</Button>
													{action.hint ? (
														<p className="max-w-xs text-[11px] text-zinc-400">
															{action.hint}
														</p>
													) : null}
												</div>
											),
										)}
									</div>
								</section>
							</>
						) : (
							<Text className="text-sm text-zinc-500">
								Selecciona un evento para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
