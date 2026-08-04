import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const STATUS = {
	ok: "emerald",
	pending: "amber",
	critical: "red",
	disabled: "zinc",
};

export default function ConfigDrawer({ open, config = null, onClose }) {
	const { configDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const configIdRef = useRef(config?.id ?? null);

	openRef.current = open;
	configIdRef.current = config?.id ?? null;

	const detailReady =
		configDetail?.id && config?.id && configDetail.id === config.id;
	const loading = Boolean(open && config?.id && !detailReady);
	const detail = detailReady ? configDetail : null;

	useEffect(() => {
		if (!open || !config?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === config.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = config.id;
			return;
		}
		if (requestedIdRef.current === config.id) {
			return;
		}

		requestedIdRef.current = config.id;
		const gen = ++requestGenRef.current;
		const configId = config.id;

		router.reload({
			only: ["configDetail"],
			data: { config_id: configId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					configIdRef.current !== configId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, config?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de configuración
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{config?.name || "Configuración"}
							</Headless.DialogTitle>
							{config ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={STATUS[config.status] || "zinc"}>
										{config.status_label}
									</Badge>
									<Badge color="sky">{config.category}</Badge>
									<AnalyticsTruthBadge truth={config.truth} />
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
										{detail.description_full || detail.description}
									</Text>
								</section>

								<section className="grid gap-3 sm:grid-cols-2">
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Categoría
										</p>
										<p className="mt-1 text-sm font-medium">
											{detail.category}
										</p>
									</div>
									<div>
										<p className="text-[11px] uppercase tracking-wide text-zinc-400">
											Clave config
										</p>
										<p className="mt-1 break-all font-mono text-xs">
											{detail.config_key}
										</p>
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Valor actual
										{detail.sensitive ? " (secreto oculto)" : ""}
									</h3>
									<pre className="overflow-x-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
										{detail.value_display || detail.value}
									</pre>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Dependencias
									</h3>
									{(detail.dependencies || []).length ? (
										<ul className="space-y-2">
											{detail.dependencies.map((dep) => (
												<li
													key={dep.key}
													className="flex flex-wrap items-center justify-between gap-2 text-sm"
												>
													<span className="break-all font-mono text-xs">
														{dep.key}
													</span>
													<div className="flex items-center gap-2">
														<Badge color={dep.filled ? "emerald" : "amber"}>
															{dep.filled ? "Presente" : "Vacío"}
														</Badge>
														<AnalyticsTruthBadge
															truth={dep.truth || "disponible"}
														/>
													</div>
												</li>
											))}
										</ul>
									) : (
										<Text className="text-sm text-zinc-500">
											Sin dependencias declaradas.
										</Text>
									)}
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Módulos relacionados
									</h3>
									<div className="flex flex-wrap gap-1.5">
										{(detail.related_modules || []).map((mod) => (
											<Badge key={mod} color="zinc">
												{mod}
											</Badge>
										))}
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
										Documentación
									</h3>
									<Text className="text-sm">{detail.documentation}</Text>
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
								Selecciona una configuración para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
