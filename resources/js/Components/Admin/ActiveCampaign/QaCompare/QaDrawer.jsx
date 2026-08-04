import { useEffect, useRef } from "react";
import * as Headless from "@headlessui/react";
import { router, usePage } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";

const STATUS = {
	equal: "emerald",
	different: "amber",
	pending: "zinc",
};

export default function QaDrawer({ open, row = null, onClose }) {
	const { compareDetail } = usePage().props;
	const requestedIdRef = useRef(null);
	const requestGenRef = useRef(0);
	const openRef = useRef(open);
	const rowIdRef = useRef(row?.id ?? null);

	openRef.current = open;
	rowIdRef.current = row?.id ?? null;

	const detailReady =
		compareDetail?.id && row?.id && compareDetail.id === row.id;
	const loading = Boolean(open && row?.id && !detailReady);
	const detail = detailReady ? compareDetail : null;

	useEffect(() => {
		if (!open || !row?.id) {
			requestedIdRef.current = null;
			requestGenRef.current += 1;
			return;
		}
		if (requestedIdRef.current === row.id && detailReady) {
			return;
		}
		if (detailReady) {
			requestedIdRef.current = row.id;
			return;
		}
		if (requestedIdRef.current === row.id) {
			return;
		}

		requestedIdRef.current = row.id;
		const gen = ++requestGenRef.current;
		const rowId = row.id;

		router.reload({
			only: ["compareDetail"],
			data: { row_id: rowId },
			preserveState: true,
			preserveScroll: true,
			onFinish: () => {
				if (
					!openRef.current ||
					rowIdRef.current !== rowId ||
					requestGenRef.current !== gen
				) {
					return;
				}
			},
		});
	}, [open, row?.id, detailReady]);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 transition data-closed:opacity-0" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="min-w-0 space-y-1">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle de comparación
							</p>
							<Headless.DialogTitle className="truncate text-lg font-semibold text-zinc-950 dark:text-white">
								{row?.name || "Comparación"}
							</Headless.DialogTitle>
							{row ? (
								<div className="flex flex-wrap gap-1.5">
									<Badge color={STATUS[row.compare_status] || "zinc"}>
										{row.compare_status_label}
									</Badge>
									<Badge color="sky">{row.category}</Badge>
									<AnalyticsTruthBadge truth={row.truth} />
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
										Configuración QA
									</h3>
									<pre className="rounded-lg bg-zinc-50 p-3 font-mono text-xs dark:bg-zinc-800">
										{detail.qa_config?.value || detail.qa_value}
									</pre>
									<p className="text-xs text-zinc-500">
										{detail.qa_config?.note || detail.qa_note}
									</p>
									<AnalyticsTruthBadge
										truth={detail.qa_config?.truth || detail.qa_truth}
									/>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Configuración Producción
									</h3>
									<pre className="rounded-lg bg-zinc-50 p-3 font-mono text-xs dark:bg-zinc-800">
										{detail.prod_config?.value || detail.prod_value}
									</pre>
									<p className="text-xs text-zinc-500">
										{detail.prod_config?.note || detail.prod_note}
									</p>
									<AnalyticsTruthBadge
										truth={detail.prod_config?.truth || detail.prod_truth}
									/>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Estado
									</h3>
									<Badge color={STATUS[detail.compare_status] || "zinc"}>
										{detail.compare_status_label}
									</Badge>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Impacto
									</h3>
									<Text className="text-sm">{detail.impact}</Text>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Módulos afectados
									</h3>
									<div className="flex flex-wrap gap-1.5">
										{(detail.affected_modules || []).map((mod) => (
											<Badge key={mod} color="zinc">
												{mod}
											</Badge>
										))}
									</div>
								</section>

								<section className="space-y-2">
									<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
										Recomendaciones
									</h3>
									<ul className="list-inside list-disc space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
										{(detail.recommendations || []).map((rec) => (
											<li key={rec}>{rec}</li>
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
							</>
						) : (
							<Text className="text-sm text-zinc-500">
								Selecciona una fila para ver el detalle.
							</Text>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
