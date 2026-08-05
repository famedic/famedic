import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import ConfidenceBadge from "@/Components/Admin/ClinicalInterpreter/AiReview/ConfidenceBadge";

function confidenceTone(pct) {
	if (pct == null) return "zinc";
	if (pct >= 80) return "emerald";
	if (pct >= 55) return "amber";
	return "red";
}

function MetaGrid({ match }) {
	if (!match) return null;

	const cells = [
		{ label: "Precio", value: match.price },
		{ label: "Laboratorio", value: match.laboratory },
		{ label: "Tiempo estimado", value: match.delivery_time },
	].filter((c) => c.value);

	if (cells.length === 0) return null;

	return (
		<dl className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-3">
			{cells.map((cell) => (
				<div key={cell.label} className="min-w-0">
					<dt className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
						{cell.label}
					</dt>
					<dd className="mt-0.5 truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">
						{cell.value}
					</dd>
				</div>
			))}
		</dl>
	);
}

function CompactResolved({ title, tone, item, onEdit, onExplain }) {
	const styles =
		tone === "deferred"
			? "border-amber-200/70 bg-amber-50/40 dark:border-amber-800/40 dark:bg-amber-950/20"
			: tone === "confirmed"
				? "border-emerald-200/70 bg-emerald-50/40 dark:border-emerald-800/40 dark:bg-emerald-950/20"
				: "border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-950/40";
	const titleClass =
		tone === "deferred"
			? "text-amber-900 dark:text-amber-200"
			: tone === "confirmed"
				? "text-emerald-800 dark:text-emerald-300"
				: "text-zinc-600 dark:text-zinc-300";

	return (
		<article
			className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3.5 ${styles}`}
		>
			<div className="min-w-0 space-y-1.5">
				<p className={`text-sm font-semibold ${titleClass}`}>{title}</p>
				{item && <ConfidenceBadge item={item} />}
			</div>
			<div className="flex shrink-0 items-center gap-1">
				{onExplain && (
					<Button plain className="!text-sm" onClick={onExplain}>
						¿Por qué?
					</Button>
				)}
				<Button plain className="!text-sm" onClick={onEdit}>
					Editar
				</Button>
			</div>
		</article>
	);
}

/**
 * Study validation card — never blocks the operator.
 */
export default function ValidateStudyCard({
	item,
	alternativesOpen = false,
	onConfirm,
	onToggleAlternatives,
	onSelectAlternative,
	onEdit,
	onOmit,
	onDefer,
	onManualSearch,
	onExplain,
}) {
	const confirmed = ["confirmed", "corrected"].includes(item.validation_status);
	const omitted =
		item.validation_status === "ignored" && item.resolution !== "deferred";
	const deferred =
		item.validation_status === "ignored" && item.resolution === "deferred";
	const match = item.match;
	const alternatives = (item.alternatives || []).filter(
		(alt) => alt.catalog_id !== match?.catalog_id,
	);
	const notFound = !match;

	if (confirmed) {
		return (
			<CompactResolved
				title={
					item.validation_status === "corrected"
						? "Confirmado · corrección manual"
						: "Confirmado"
				}
				tone="confirmed"
				item={item}
				onEdit={() => onEdit?.(item)}
				onExplain={onExplain ? () => onExplain(item) : undefined}
			/>
		);
	}

	if (deferred) {
		return (
			<CompactResolved
				title="Pendiente de resolución"
				tone="deferred"
				item={item}
				onEdit={() => onEdit?.(item)}
				onExplain={onExplain ? () => onExplain(item) : undefined}
			/>
		);
	}

	if (omitted) {
		return (
			<CompactResolved
				title="Omitido"
				tone="omitted"
				item={item}
				onEdit={() => onEdit?.(item)}
				onExplain={onExplain ? () => onExplain(item) : undefined}
			/>
		);
	}

	return (
		<article className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80 sm:p-5">
			<div className="space-y-4">
				<div className="flex flex-wrap items-start justify-between gap-2">
					<div>
						<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
							Nombre detectado
						</p>
						<p className="mt-1 text-base font-semibold text-zinc-900 dark:text-zinc-50">
							{item.detected_name}
						</p>
					</div>
					<div className="flex flex-wrap items-center gap-1.5">
						<ConfidenceBadge item={item} />
						{notFound && (
							<Badge color="amber" className="!text-[10px]">
								No encontrado
							</Badge>
						)}
					</div>
				</div>

				{match ? (
					<div className="rounded-xl bg-zinc-50/90 px-3.5 py-3 dark:bg-zinc-950/50">
						<div className="flex flex-wrap items-start justify-between gap-2">
							<div className="min-w-0">
								<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
									Mejor coincidencia
								</p>
								<p className="mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{match.name}
								</p>
							</div>
							{match.similarity != null && (
								<Badge
									color={confidenceTone(Number(match.similarity))}
									className="!shrink-0 !text-[10px]"
								>
									{match.similarity}% confianza
								</Badge>
							)}
						</div>
						<MetaGrid match={match} />
						{onExplain && (
							<div className="mt-2">
								<Button
									plain
									className="!text-xs"
									onClick={() => onExplain(item)}
								>
									¿Por qué la IA eligió este estudio?
								</Button>
							</div>
						)}
					</div>
				) : (
					<div className="rounded-xl border border-amber-200/80 bg-amber-50/50 px-3.5 py-3 dark:border-amber-800/40 dark:bg-amber-950/20">
						<p className="text-sm font-medium text-amber-900 dark:text-amber-200">
							No encontrado en el catálogo
						</p>
						<p className="mt-1 text-xs text-zinc-500">
							Puedes buscar manualmente, omitirlo o marcarlo para revisión. El
							flujo no se detiene.
						</p>
					</div>
				)}

				{alternativesOpen && (
					<div className="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
						<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
							Alternativas
						</p>
						{alternatives.length === 0 ? (
							<p className="text-xs text-zinc-400">
								No hay otras opciones automáticas. Usa búsqueda manual u omite
								el estudio.
							</p>
						) : (
							<ul className="space-y-2">
								{alternatives.map((alt) => (
									<li key={alt.catalog_id}>
										<button
											type="button"
											onClick={() => onSelectAlternative?.(item, alt)}
											className="w-full rounded-xl border border-zinc-200 px-3 py-2.5 text-left transition hover:border-famedic-light/50 hover:bg-famedic-light/5 dark:border-zinc-700 dark:hover:border-famedic-light/40"
										>
											<div className="flex items-start justify-between gap-2">
												<div className="min-w-0">
													<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
														{alt.name}
													</p>
													<p className="mt-0.5 text-[11px] text-zinc-400">
														{[alt.laboratory, alt.price, alt.delivery_time]
															.filter(Boolean)
															.join(" · ")}
													</p>
												</div>
												{alt.similarity != null && (
													<span className="shrink-0 text-xs font-semibold tabular-nums text-zinc-500">
														{alt.similarity}%
													</span>
												)}
											</div>
										</button>
									</li>
								))}
							</ul>
						)}
					</div>
				)}

				<div className="flex flex-wrap gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
					{match && (
						<Button onClick={() => onConfirm?.(item)} className="!text-sm">
							Confirmar
						</Button>
					)}
					{(match || alternatives.length > 0) && (
						<Button
							outline
							onClick={() => onToggleAlternatives?.(item)}
							className="!text-sm"
						>
							{alternativesOpen ? "Ocultar opciones" : "Cambiar"}
						</Button>
					)}
					{onManualSearch && (
						<Button
							outline
							onClick={() => onManualSearch?.(item)}
							className="!text-sm"
						>
							Buscar manualmente
						</Button>
					)}
					<Button outline onClick={() => onOmit?.(item)} className="!text-sm">
						Omitir este estudio
					</Button>
					<Button plain onClick={() => onDefer?.(item)} className="!text-sm">
						Marcar para revisión posterior
					</Button>
				</div>
			</div>
		</article>
	);
}
