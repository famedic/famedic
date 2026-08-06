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

export default function FieldsDrawer({ open, field = null, onClose }) {
	const { fieldDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const fieldIdRef = useRef(field?.id ?? null);

	openRef.current = open;
	fieldIdRef.current = field?.id ?? null;

	const detailReady =
		fieldDetail?.id && field?.id && fieldDetail.id === field.id;
	const loading = Boolean(open && field?.id && !detailReady);
	const detail = detailReady ? fieldDetail : null;

	useEffect(() => {
		if (!open || !field?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === field.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = field.id;
			return;
		}
		if (requestedIdRef.current === field.id) {
			return;
		}

		requestedIdRef.current = field.id;
		const gen = ++requestGenRef.current;
		const fieldId = field.id;

		router.reload({
			only: ["fieldDetail"],
			data: { field_id: fieldId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					fieldIdRef.current !== fieldId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, field?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de campo
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{field?.name || "Campo"}
							</Headless.DialogTitle>
							{field ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={STATUS[field.status] || "zinc"}>
										{field.status_label}
									</Badge>
									<Badge color="zinc">{field.type}</Badge>
									<AnalyticsTruthBadge truth={field.truth} />
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
											Tipo
										</p>
										<p className="mt-1 text-sm font-medium">{detail.type}</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Origen
										</p>
										<p className="mt-1 text-sm font-medium">{detail.origin}</p>
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Validaciones
									</h3>
									<ul className="space-y-2">
										{(detail.validations || []).map((rule, i) => (
											<li
												key={`${rule.label}-${i}`}
												className="flex flex-wrap items-center justify-between gap-2 text-sm"
											>
												<span>{rule.label}</span>
												<AnalyticsTruthBadge truth={rule.truth || "proxy"} />
											</li>
										))}
									</ul>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Sincronización
									</h3>
									<div className="grid gap-2 sm:grid-cols-2">
										{Object.entries(detail.sync_detail || {})
											.filter(([k]) => k !== "truth")
											.map(([key, value]) => (
												<div key={key}>
													<p className="text-[11px] uppercase tracking-wide text-zinc-400">
														{key}
													</p>
													<p className="mt-1 break-all text-sm font-medium">
														{String(value)}
													</p>
												</div>
											))}
									</div>
									<AnalyticsTruthBadge
										truth={detail.sync_detail?.truth || "proxy"}
									/>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Campos relacionados
									</h3>
									{(detail.related_fields || []).length ? (
										<ul className="space-y-1 text-sm">
											{detail.related_fields.map((rel) => (
												<li key={rel.id} className="flex justify-between gap-2">
													<span>{rel.name}</span>
													<span className="text-zinc-400">{rel.type}</span>
												</li>
											))}
										</ul>
									) : (
										<Text className="text-sm text-zinc-500">
											Sin campos de la misma familia.
										</Text>
									)}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Journey relacionado
									</h3>
									<Text className="text-sm">{detail.related_journey}</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Automation relacionado
									</h3>
									<ul className="space-y-2">
										{(detail.related_automation || []).map((auto, i) => (
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
										Analytics relacionado
									</h3>
									<ul className="space-y-2">
										{(detail.related_analytics || []).map((row, i) => (
											<li
												key={`${row.label}-${i}`}
												className="flex flex-wrap items-center justify-between gap-2 text-sm"
											>
												<span>{row.label}</span>
												<AnalyticsTruthBadge truth={row.truth || "proxy"} />
											</li>
										))}
									</ul>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Uso
									</h3>
									<Text className="text-sm">
										{detail.usage_detail || detail.usage_label}
									</Text>
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
								Selecciona un campo para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
