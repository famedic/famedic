import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const STATUS = {
	active: "emerald",
	unused: "amber",
	missing: "red",
};

export default function TagsDrawer({ open, tag = null, onClose }) {
	const { tagDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const tagIdRef = useRef(tag?.id ?? null);

	openRef.current = open;
	tagIdRef.current = tag?.id ?? null;

	const detailReady = tagDetail?.id && tag?.id && tagDetail.id === tag.id;
	const loading = Boolean(open && tag?.id && !detailReady);
	const detail = detailReady ? tagDetail : null;

	useEffect(() => {
		if (!open || !tag?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === tag.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = tag.id;
			return;
		}
		if (requestedIdRef.current === tag.id) {
			return;
		}

		requestedIdRef.current = tag.id;
		const gen = ++requestGenRef.current;
		const tagId = tag.id;

		router.reload({
			only: ["tagDetail"],
			data: { tag_id: tagId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					tagIdRef.current !== tagId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, tag?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de tag
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{tag?.name || "Tag"}
							</Headless.DialogTitle>
							{tag ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={STATUS[tag.status] || "zinc"}>
										{tag.status_label}
									</Badge>
									<Badge color="zinc">{tag.automation_label}</Badge>
									<AnalyticsTruthBadge truth={tag.truth} />
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
									<Text className="text-sm">
										{detail.description_full || detail.description || "—"}
									</Text>
								</section>

								<section className="grid gap-3 sm:grid-cols-2">
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Origen
										</p>
										<p className="mt-1 text-sm font-medium">{detail.origin}</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Cantidad de contactos
										</p>
										<p className="mt-1 flex flex-wrap items-center gap-2 text-sm font-medium">
											{detail.contacts_count ?? detail.contacts}
											<AnalyticsTruthBadge
												truth={detail.contacts_truth || "instrumentacion"}
											/>
										</p>
									</div>
									{detail.ac_id ? (
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												ID ActiveCampaign
											</p>
											<p className="mt-1 text-sm font-medium">{detail.ac_id}</p>
										</div>
									) : null}
									{detail.config_key ? (
										<div>
											<p className="text-[11px] uppercase tracking-wide text-zinc-400">
												Clave config
											</p>
											<p className="mt-1 break-all text-sm font-medium">
												{detail.config_key}
											</p>
										</div>
									) : null}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Automatizaciones relacionadas
									</h3>
									<ul className="space-y-2">
										{(detail.related_automations || []).map((auto, i) => (
											<li
												key={`${auto.label}-${i}`}
												className="flex flex-wrap items-center justify-between gap-2 text-sm"
											>
												<span>{auto.label}</span>
												<AnalyticsTruthBadge truth={auto.truth || "proxy"} />
											</li>
										))}
									</ul>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Journey relacionado
									</h3>
									<Text className="text-sm">{detail.related_journey}</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Uso en campañas
									</h3>
									<p className="flex flex-wrap items-center gap-2 text-sm">
										{detail.campaign_usage?.value || "No disponible"}
										<AnalyticsTruthBadge
											truth={detail.campaign_usage?.truth || "instrumentacion"}
										/>
									</p>
									{detail.campaign_usage?.note ? (
										<p className="text-xs text-zinc-500">
											{detail.campaign_usage.note}
										</p>
									) : null}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Timeline relacionado
									</h3>
									<Text className="text-sm">{detail.related_timeline}</Text>
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
								Selecciona un tag para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
