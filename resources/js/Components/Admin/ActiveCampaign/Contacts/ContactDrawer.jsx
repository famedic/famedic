import { useCallback, useEffect, useRef, useState } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import ContactSummary from "./ContactSummary";
import ContactTimeline from "./ContactTimeline";
import ContactEvents from "./ContactEvents";
import ContactTags from "./ContactTags";
import ContactLeadScore from "./ContactLeadScore";
import ContactLists from "./ContactLists";
import ContactCustomFields from "./ContactCustomFields";
import ContactEngagement from "./ContactEngagement";
import ContactPurchases from "./ContactPurchases";
import ContactLaboratories from "./ContactLaboratories";
import ContactMemberships from "./ContactMemberships";
import ContactInvoices from "./ContactInvoices";
import ContactCoupons from "./ContactCoupons";
import ContactBeneficiaries from "./ContactBeneficiaries";
import ContactAutomations from "./ContactAutomations";
import ContactInsights from "./ContactInsights";

function MirrorStatusBanner({ mirror, loading }) {
	if (loading && !mirror) {
		return (
			<div className="mt-2 flex items-center gap-2 text-[11px] text-zinc-400">
				<span className="size-1.5 animate-pulse rounded-full bg-zinc-300" />
				Consultando ActiveCampaign…
			</div>
		);
	}

	if (!mirror) {
		return null;
	}

	if (mirror.status === "ok") {
		return (
			<div className="mt-2 space-y-0.5">
				<div className="flex items-center gap-2 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
					<span className="size-1.5 rounded-full bg-emerald-500" />
					ActiveCampaign sincronizado
				</div>
				{mirror.synced_at_human ? (
					<Text className="text-[11px] text-zinc-400">
						Última lectura: {mirror.synced_at_human}
						{mirror.from_cache ? " (caché)" : ""}
					</Text>
				) : null}
			</div>
		);
	}

	return (
		<div className="mt-2 space-y-0.5">
			<div className="flex items-center gap-2 text-[11px] font-medium text-rose-700 dark:text-rose-400">
				<span className="size-1.5 rounded-full bg-rose-500" />
				Error consultando ActiveCampaign
			</div>
			{mirror.message ? (
				<Text className="text-[11px] text-zinc-400">{mirror.message}</Text>
			) : null}
		</div>
	);
}

/**
 * Vista 360 en panel lateral (HubSpot-style).
 * Summary primero; Timeline + secciones + Mirror AC en un único partial (drawerExtras).
 */
export default function ContactDrawer({
	open,
	contact = null,
	drawer = null,
	onClose,
}) {
	const { drawerExtras } = usePage().props;
	const summaryRequestRef = useRef(null);
	const extrasRequestRef = useRef(null);
	const openRef = useRef(open);
	const contactIdRef = useRef(null);
	const requestGenRef = useRef(0);

	const [extras, setExtras] = useState(null);
	const [extrasLoading, setExtrasLoading] = useState(false);

	openRef.current = open;
	contactIdRef.current = contact?.id ?? null;

	const summaryReady =
		drawer?.contact_id && contact?.id && drawer.contact_id === contact.id;
	const summaryLoading = Boolean(open && contact?.id && !summaryReady);

	const extrasReady =
		extras?.contact_id && contact?.id && extras.contact_id === contact.id;
	const timelineLoading = Boolean(summaryReady && !extrasReady);
	const mirror = extrasReady ? extras?.mirror || null : null;
	const mirrorLoading = Boolean(summaryReady && (extrasLoading || !extrasReady));

	const resetExtras = useCallback(() => {
		setExtras(null);
		setExtrasLoading(false);
		extrasRequestRef.current = null;
	}, []);

	useEffect(() => {
		if (!open || !contact?.id) {
			summaryRequestRef.current = null;
			requestGenRef.current += 1;
			resetExtras();
			return;
		}

		if (summaryRequestRef.current === contact.id) {
			return;
		}

		summaryRequestRef.current = contact.id;
		requestGenRef.current += 1;
		resetExtras();

		const gen = requestGenRef.current;
		router.reload({
			only: ["drawer"],
			data: { drawer_contact_id: contact.id },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					contactIdRef.current !== contact.id ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, contact?.id, resetExtras]);

	useEffect(() => {
		if (!open || !summaryReady || !contact?.id) {
			return;
		}
		if (extrasRequestRef.current === contact.id) {
			return;
		}
		if (extrasReady && extras.contact_id === contact.id) {
			return;
		}

		extrasRequestRef.current = contact.id;
		setExtrasLoading(true);
		const gen = requestGenRef.current;
		const requestedId = contact.id;

		router.reload({
			only: ["drawerExtras"],
			data: { drawer_contact_id: requestedId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					contactIdRef.current !== requestedId ||
					requestGenRef.current !== gen
				) {
					return;
				}
				setExtrasLoading(false);
			},
		});
	}, [open, summaryReady, contact?.id, extrasReady, extras?.contact_id]);

	useEffect(() => {
		if (
			!drawerExtras?.contact_id ||
			!contact?.id ||
			drawerExtras.contact_id !== contact.id
		) {
			return;
		}
		if (!openRef.current) {
			return;
		}
		setExtras(drawerExtras);
		setExtrasLoading(false);
	}, [drawerExtras, contact?.id]);

	const sectionPayload = (key) =>
		extrasReady ? extras?.sections?.[key] || null : null;

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-zinc-950/30 transition data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in dark:bg-zinc-950/60"
			/>

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-4 sm:pl-10">
						<Headless.DialogPanel
							transition
							className="pointer-events-auto w-screen max-w-xl transform transition duration-300 ease-in-out data-[closed]:translate-x-full data-[enter]:ease-out data-[leave]:ease-in"
						>
							<div className="flex h-full flex-col border-l border-zinc-200 bg-zinc-50 shadow-2xl dark:border-zinc-700 dark:bg-zinc-950">
								<header className="shrink-0 border-b border-zinc-200 bg-white px-5 py-4 dark:border-zinc-700 dark:bg-zinc-900">
									<div className="flex items-start justify-between gap-3">
										<div className="min-w-0 space-y-1">
											<div className="flex flex-wrap items-center gap-2">
												<Headless.DialogTitle className="truncate text-base font-semibold text-zinc-950 dark:text-white">
													{contact?.name || "Contacto"}
												</Headless.DialogTitle>
												<Badge color="sky">Vista 360</Badge>
											</div>
											<Text className="truncate text-xs text-zinc-500 dark:text-zinc-400">
												{contact?.email || "—"}
												{contact?.phone && contact.phone !== "—"
													? ` · ${contact.phone}`
													: ""}
											</Text>
											<Text className="text-[11px] text-zinc-400">
												{summaryReady
													? extrasReady
														? "Ficha bajo demanda"
														: "Cargando timeline y secciones…"
													: "Cargando resumen…"}
											</Text>
											<MirrorStatusBanner
												mirror={mirror}
												loading={mirrorLoading}
											/>
										</div>
										<Button
											plain
											onClick={onClose}
											aria-label="Cerrar panel"
										>
											<XMarkIcon className="size-5" />
										</Button>
									</div>
								</header>

								<div className="flex-1 space-y-4 overflow-y-auto px-5 py-5">
									<nav
										aria-label="Secciones del contacto"
										className="flex flex-wrap gap-1.5"
									>
										{[
											"Resumen",
											"Timeline",
											"Eventos",
											"Tags",
											"Compras",
											"Labs",
											"Membresías",
											"Insights",
										].map((label) => (
											<span
												key={label}
												className="rounded-full border border-zinc-200 bg-white px-2.5 py-0.5 text-[11px] font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400"
											>
												{label}
											</span>
										))}
									</nav>

									<ContactSummary
										summary={summaryReady ? drawer.summary : null}
										loading={summaryLoading}
									/>
									<ContactTimeline
										timeline={extrasReady ? extras.timeline : null}
										loading={timelineLoading || extrasLoading}
									/>
									<ContactLeadScore
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactEngagement
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactEvents
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactTags
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactLists
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactCustomFields
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactPurchases
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("purchases")}
										onRequest={null}
									/>
									<ContactLaboratories
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("laboratories")}
										onRequest={null}
									/>
									<ContactMemberships
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("memberships")}
										onRequest={null}
									/>
									<ContactInvoices
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("invoices")}
										onRequest={null}
									/>
									<ContactCoupons
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("coupons")}
										onRequest={null}
									/>
									<ContactBeneficiaries
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										payload={sectionPayload("beneficiaries")}
										onRequest={null}
									/>
									<ContactAutomations
										ready={extrasReady}
										loading={extrasLoading && !extrasReady}
										mirror={mirror}
									/>
									<ContactInsights />
								</div>
							</div>
						</Headless.DialogPanel>
					</div>
				</div>
			</div>
		</Headless.Dialog>
	);
}
