import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Text } from "@/Components/Catalyst/text";
import ConfidenceBadge from "./ConfidenceBadge";
import {
	formatConfidencePct,
	reasonToHumanLines,
} from "./confidenceHelpers";

/**
 * Panel: ¿Por qué la IA eligió este estudio?
 * Human language only — no JSON.
 */
export default function StudyConfidenceDrawer({ open, onClose, item = null }) {
	if (!item) {
		return (
			<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
				<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/30 dark:bg-zinc-950/50" />
			</Headless.Dialog>
		);
	}

	const match = item.match;
	const title = (match?.name || item.name || item.detected_name || "Estudio").toUpperCase();
	const confidence =
		formatConfidencePct(match?.similarity) ??
		formatConfidencePct(item.detection_confidence);
	const reasons = reasonToHumanLines(match, item.pipeline);
	const alternatives = (item.alternatives || []).filter(
		(alt) => alt.catalog_id !== match?.catalog_id,
	);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-zinc-950/30 transition duration-200 data-closed:opacity-0 dark:bg-zinc-950/50"
			/>
			<div className="fixed inset-0 flex justify-end sm:p-3">
				<Headless.DialogPanel
					transition
					className="flex h-full w-full max-w-md flex-col border-l border-zinc-200 bg-white shadow-lg transition duration-300 ease-out data-closed:translate-x-8 data-closed:opacity-0 dark:border-zinc-700 dark:bg-zinc-950 sm:rounded-l-xl sm:border"
				>
					<header className="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
						<div className="flex items-start justify-between gap-3">
							<div className="min-w-0 space-y-2">
								<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
									AI Review
								</p>
								<Headless.DialogTitle className="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
									{title}
								</Headless.DialogTitle>
								<ConfidenceBadge item={item} />
							</div>
							<button
								type="button"
								onClick={onClose}
								aria-label="Cerrar"
								className="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
							>
								<XMarkIcon className="size-5" />
							</button>
						</div>
					</header>

					<div className="flex-1 space-y-6 overflow-y-auto px-5 py-5">
						<section className="space-y-1.5">
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Detectado en receta como
							</p>
							<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
								“{item.detected_name || "—"}”
							</p>
						</section>

						<section className="space-y-1.5">
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Confianza IA
							</p>
							{confidence != null ? (
								<p className="text-3xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-zinc-50">
									{confidence}
									<span className="ml-1 text-base font-medium text-zinc-400">
										%
									</span>
								</p>
							) : (
								<Text className="!text-sm text-zinc-500">
									Confianza no disponible en este expediente.
								</Text>
							)}
						</section>

						<section className="space-y-2">
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Motivo de coincidencia
							</p>
							{reasons.length > 0 ? (
								<ul className="space-y-2">
									{reasons.map((line) => (
										<li
											key={line}
											className="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-200"
										>
											<span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-famedic-light" />
											{line}
										</li>
									))}
								</ul>
							) : (
								<p className="text-sm text-zinc-400">
									No hay un motivo detallado registrado para esta coincidencia.
								</p>
							)}
						</section>

						<section className="space-y-2">
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Alternativas consideradas
							</p>
							{alternatives.length > 0 ? (
								<ul className="space-y-2">
									{alternatives.map((alt) => {
										const pct = formatConfidencePct(alt.similarity);
										return (
											<li
												key={alt.catalog_id || alt.name}
												className="flex items-baseline justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900"
											>
												<span className="text-sm text-zinc-800 dark:text-zinc-100">
													{alt.name}
												</span>
												{pct != null && (
													<span className="shrink-0 text-xs font-semibold tabular-nums text-zinc-500">
														{pct}%
													</span>
												)}
											</li>
										);
									})}
								</ul>
							) : (
								<p className="text-sm text-zinc-400">
									Sin alternativas registradas.
								</p>
							)}
						</section>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
