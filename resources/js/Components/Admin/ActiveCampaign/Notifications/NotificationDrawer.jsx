import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

const PRIORITY = {
	critical: "red",
	warning: "amber",
	info: "sky",
};

const STATUS = {
	nueva: "blue",
	vista: "zinc",
	en_proceso: "amber",
	resuelta: "emerald",
};

export default function NotificationDrawer({
	open,
	notification = null,
	onClose,
}) {
	const { notificationDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const notificationIdRef = useRef(notification?.id ?? null);

	openRef.current = open;
	notificationIdRef.current = notification?.id ?? null;

	const detailReady =
		notificationDetail?.id &&
		notification?.id &&
		notificationDetail.id === notification.id;
	const loading = Boolean(open && notification?.id && !detailReady);
	const detail = detailReady ? notificationDetail : null;

	useEffect(() => {
		if (!open || !notification?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === notification.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = notification.id;
			return;
		}
		if (requestedIdRef.current === notification.id) {
			return;
		}

		requestedIdRef.current = notification.id;
		const gen = ++requestGenRef.current;
		const notificationId = notification.id;

		router.reload({
			only: ["notificationDetail"],
			data: {
				notification_id: notificationId,
			},
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					notificationIdRef.current !== notificationId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, notification?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de notificación
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{notification?.title || "Notificación"}
							</Headless.DialogTitle>
							{notification ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={PRIORITY[notification.priority] || "zinc"}>
										{notification.priority_label}
									</Badge>
									<Badge color={STATUS[notification.status] || "zinc"}>
										{notification.status_label}
									</Badge>
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
											{detail.origin_detail || detail.origin}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Evento relacionado
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.related_event || "No disponible"}
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
										{detail.contact_id ? (
											<>
												<Button
													href={route("admin.activecampaign.contacts", {
														search: detail.patient_email || "",
													})}
													outline
												>
													Ir al CRM
												</Button>
												<Button
													href={route(
														"admin.activecampaign.customer-journey",
														{ contact_id: detail.contact_id },
													)}
													outline
												>
													Abrir Journey
												</Button>
											</>
										) : null}
									</div>
								</section>

								{detail.raw ? (
									<section className="space-y-2">
										<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
											Contexto
										</h3>
										<pre className="overflow-x-auto rounded-lg bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
											{JSON.stringify(detail.raw, null, 2)}
										</pre>
									</section>
								) : null}
							</>
						) : (
							<Text className="text-sm text-zinc-500">
								Selecciona una notificación para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
