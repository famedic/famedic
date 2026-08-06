import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	matchStatusMeta,
	uiStateMeta,
} from "@/Components/Admin/ClinicalInterpreter/matchingHelpers";

function MatchMeta({ match }) {
	if (!match) return null;

	return (
		<div className="mt-1 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
			{(match.code || match.sku) && (
				<p>
					Código interno:{" "}
					<span className="font-medium text-zinc-700 dark:text-zinc-200">
						{match.code || match.sku}
					</span>
				</p>
			)}
			{match.price && (
				<p>
					Precio:{" "}
					<span className="font-medium text-zinc-700 dark:text-zinc-200">
						{match.price}
					</span>
				</p>
			)}
			{match.delivery_time && (
				<p>
					Tiempo de entrega:{" "}
					<span className="font-medium text-zinc-700 dark:text-zinc-200">
						{match.delivery_time}
					</span>
				</p>
			)}
			{match.laboratory && (
				<p>
					Laboratorio:{" "}
					<span className="font-medium text-zinc-700 dark:text-zinc-200">
						{match.laboratory}
					</span>
				</p>
			)}
		</div>
	);
}

function MatchCard({
	row,
	phase,
	onAccept,
	onChange,
	onSearch,
	onIgnore,
	onSelectAlternative,
	showActions = true,
}) {
	const engineMeta = matchStatusMeta(
		row.user_decision === "ignored"
			? "not_found"
			: row.engine_status === "partial"
				? "partial"
				: row.engine_status,
	);

	const liveState =
		phase === "searching"
			? "searching"
			: phase === "analyzing"
				? "analyzing"
				: row.user_decision === "accepted" || row.user_decision === "manual"
					? row.user_decision === "manual"
						? "manual"
						: "accepted"
					: row.user_decision === "ignored"
						? "ignored"
						: row.ui_state;

	const stateMeta = uiStateMeta(liveState);
	const busy = phase === "searching" || phase === "analyzing";
	const alternatives = row.alternatives || [];
	const needsChoice =
		!busy &&
		row.type === "laboratory" &&
		alternatives.length > 1 &&
		row.user_decision == null;

	return (
		<article className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-3 flex flex-wrap items-center gap-1.5">
				<Badge color="famedic" className="!text-[10px]">
					Estudio
				</Badge>
				<Badge color={stateMeta.color} className="!text-[10px]">
					{stateMeta.label}
				</Badge>
				{!busy && (
					<Badge color={engineMeta.color} className="!text-[10px]">
						{engineMeta.label}
					</Badge>
				)}
			</div>

			<div className="space-y-2 text-sm">
				<div>
					<p className="text-[11px] uppercase tracking-wide text-zinc-400">
						Nombre detectado
					</p>
					<p className="font-semibold text-zinc-900 dark:text-zinc-50">
						{row.detected_name}
					</p>
				</div>

				{busy ? (
					<div className="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-950/50">
						<div className="h-2 w-[70%] animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
						<div className="h-2 w-[50%] animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
						<p className="text-xs text-zinc-500">
							{phase === "searching"
								? "Buscando en catálogo Famedic…"
								: "Analizando similitud, sinónimos y ranking…"}
						</p>
					</div>
				) : (
					<>
						<div className="flex items-center gap-2 text-zinc-400">
							<span className="text-xs">↓</span>
							<span className="text-[11px] uppercase tracking-wide">
								Motor Famedic
							</span>
						</div>

						{row.match ? (
							<div>
								<p className="text-[11px] uppercase tracking-wide text-zinc-400">
									{row.engine_status === "not_found"
										? "Sugerencia similar"
										: "Estudio encontrado"}
								</p>
								<p className="font-medium text-zinc-800 dark:text-zinc-100">
									{row.match.name}
								</p>
								<div className="mt-1 flex flex-wrap gap-1.5">
									<Badge color="sky" className="!text-[10px]">
										{row.match.similarity}% confianza
									</Badge>
									<Badge
										color={
											row.match.match_status === "exact"
												? "emerald"
												: row.match.match_status === "partial"
													? "amber"
													: "red"
										}
										className="!text-[10px]"
									>
										{row.match.match_status === "exact"
											? "Exacta"
											: row.match.match_status === "partial"
												? "Parcial"
												: "No encontrada"}
									</Badge>
								</div>
								<MatchMeta match={row.match} />
							</div>
						) : (
							<div className="rounded-lg border border-red-200/70 bg-red-50/50 p-3 dark:border-red-900/40 dark:bg-red-950/20">
								<p className="text-sm font-medium text-red-700 dark:text-red-400">
									No encontrado
								</p>
								<p className="mt-1 text-xs text-zinc-500">
									Usa sugerencias similares o búsqueda manual.
								</p>
							</div>
						)}

						{alternatives.length > 0 && (
							<div className="space-y-1.5">
								<p className="text-[11px] uppercase tracking-wide text-zinc-400">
									{row.engine_status === "not_found"
										? "Sugerencias similares"
										: needsChoice
											? "Selecciona una coincidencia"
											: "Coincidencias ordenadas"}
								</p>
								<ul className="space-y-1.5">
									{alternatives.map((alt) => {
										const selected =
											row.selected_catalog_id === alt.catalog_id;
										const body = (
											<div className="flex items-start justify-between gap-2">
												<div className="min-w-0">
													<p className="text-xs font-medium text-zinc-800 dark:text-zinc-100">
														{alt.name}
													</p>
													<p className="mt-0.5 text-[11px] text-zinc-400">
														{[alt.code || alt.sku, alt.laboratory, alt.price]
															.filter(Boolean)
															.join(" · ")}
													</p>
												</div>
												<span className="shrink-0 text-xs font-semibold text-zinc-500">
													{alt.similarity}%
												</span>
											</div>
										);
										return (
											<li key={alt.catalog_id}>
												{showActions ? (
													<button
														type="button"
														onClick={() =>
															onSelectAlternative?.(row, alt)
														}
														className={`w-full rounded-lg border px-3 py-2 text-left transition ${
															selected
																? "border-famedic-light bg-sky-50/60 dark:border-famedic-light dark:bg-sky-950/30"
																: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
														}`}
													>
														{body}
													</button>
												) : (
													<div className="w-full rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
														{body}
													</div>
												)}
											</li>
										);
									})}
								</ul>
							</div>
						)}
					</>
				)}
			</div>

			{!busy && showActions && (
				<div className="mt-4 flex flex-wrap gap-1.5 border-t border-zinc-100 pt-3 dark:border-zinc-800">
					<Button
						outline
						className="!py-1.5 text-xs"
						disabled={
							row.user_decision === "ignored" ||
							(!row.match && !row.selected_catalog_id)
						}
						onClick={() => onAccept(row)}
					>
						Aceptar
					</Button>
					<Button
						outline
						className="!py-1.5 text-xs"
						onClick={() => onChange(row)}
					>
						Cambiar
					</Button>
					<Button
						outline
						className="!py-1.5 text-xs"
						onClick={() => onSearch(row)}
					>
						Buscar manualmente
					</Button>
					<Button
						plain
						className="!py-1.5 text-xs"
						onClick={() => onIgnore(row)}
					>
						Ignorar
					</Button>
				</div>
			)}
		</article>
	);
}

export default function MatchingPanel({
	matches,
	phase,
	onAccept,
	onChange,
	onSearch,
	onIgnore,
	onSelectAlternative,
	showActions = true,
}) {
	const studies = matches?.studies || [];

	return (
		<section className="flex h-full min-h-[320px] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<header className="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Panel 3 · Corazón del producto
				</p>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Clinical Matching
				</h2>
				<p className="mt-1 text-xs text-zinc-500">
					Estudios de laboratorio · catálogo Famedic
				</p>
			</header>

			<div className="flex-1 space-y-4 overflow-y-auto p-4">
				<div className="space-y-3">
					<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
						Estudios detectados
					</p>
					{studies.length === 0 ? (
						<p className="text-xs text-zinc-400">Sin estudios detectados</p>
					) : (
						studies.map((row) => (
							<MatchCard
								key={row.detection_id}
								row={row}
								phase={phase}
								onAccept={onAccept}
								onChange={onChange}
								onSearch={onSearch}
								onIgnore={onIgnore}
								onSelectAlternative={onSelectAlternative}
								showActions={showActions}
							/>
						))
					)}
				</div>
			</div>
		</section>
	);
}
