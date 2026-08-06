import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { validationStatusMeta } from "@/Components/Admin/ClinicalInterpreter/validationHelpers";

function ItemCard({
	item,
	onConfirm,
	onSelectAlternative,
	onSearch,
	onIgnore,
}) {
	const status = validationStatusMeta(item.validation_status);
	const match = item.match;
	const alternatives = item.alternatives || [];
	const locked = item.validation_status !== "pending";

	return (
		<article className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-3 flex flex-wrap items-center gap-1.5">
				<Badge color="famedic" className="!text-[10px]">
					Estudio
				</Badge>
				<Badge color={status.color} className="!text-[10px]">
					{status.label}
				</Badge>
			</div>

			<div className="space-y-2 text-sm">
				<div>
					<p className="text-[11px] uppercase tracking-wide text-zinc-400">
						Nombre detectado
					</p>
					<p className="font-semibold text-zinc-900 dark:text-zinc-50">
						{item.detected_name}
					</p>
				</div>

				<div className="flex items-center gap-2 text-zinc-400">
					<span className="text-xs">↓</span>
					<span className="text-[11px] uppercase tracking-wide">
						Coincidencia encontrada
					</span>
				</div>

				{match ? (
					<div>
						<p className="font-medium text-zinc-800 dark:text-zinc-100">
							{match.name}
						</p>
						<div className="mt-1 flex flex-wrap gap-1.5">
							<Badge color="sky" className="!text-[10px]">
								{match.similarity ?? "—"}% confianza
							</Badge>
							{(match.code || match.sku) && (
								<Badge color="zinc" className="!text-[10px]">
									{match.code || match.sku}
								</Badge>
							)}
							{match.price && (
								<Badge color="zinc" className="!text-[10px]">
									{match.price}
								</Badge>
							)}
							{match.laboratory && (
								<Badge color="zinc" className="!text-[10px]">
									{match.laboratory}
								</Badge>
							)}
						</div>
					</div>
				) : (
					<div className="rounded-lg border border-red-200/70 bg-red-50/50 p-3 dark:border-red-900/40 dark:bg-red-950/20">
						<p className="text-sm font-medium text-red-700 dark:text-red-400">
							No encontrado
						</p>
						<p className="mt-1 text-xs text-zinc-500">
							Usa búsqueda manual para seleccionar un estudio del catálogo.
						</p>
					</div>
				)}

				{!locked && alternatives.length > 1 && (
					<div className="space-y-1.5">
						<p className="text-[11px] uppercase tracking-wide text-zinc-400">
							Selector de coincidencias
						</p>
						<ul className="space-y-1.5">
							{alternatives.map((alt) => {
								const selected =
									item.selected_catalog_id === alt.catalog_id;
								return (
									<li key={alt.catalog_id}>
										<button
											type="button"
											onClick={() => onSelectAlternative(item, alt)}
											className={`w-full rounded-lg border px-3 py-2 text-left transition ${
												selected
													? "border-famedic-light bg-sky-50/60 dark:border-famedic-light dark:bg-sky-950/30"
													: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700"
											}`}
										>
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
										</button>
									</li>
								);
							})}
						</ul>
					</div>
				)}
			</div>

			{!locked && (
				<div className="mt-4 flex flex-wrap gap-1.5 border-t border-zinc-100 pt-3 dark:border-zinc-800">
					<Button
						outline
						className="!py-1.5 text-xs"
						disabled={!match && !item.selected_catalog_id}
						onClick={() => onConfirm(item)}
					>
						Confirmar
					</Button>
					<Button
						outline
						className="!py-1.5 text-xs"
						onClick={() => onSearch(item)}
					>
						Cambiar coincidencia
					</Button>
					<Button
						outline
						className="!py-1.5 text-xs"
						onClick={() => onSearch(item)}
					>
						Buscar manualmente
					</Button>
					<Button
						plain
						className="!py-1.5 text-xs"
						onClick={() => onIgnore(item)}
					>
						Ignorar
					</Button>
				</div>
			)}
		</article>
	);
}

export default function HumanValidationCenter({
	items = [],
	onConfirm,
	onSelectAlternative,
	onSearch,
	onIgnore,
}) {
	const studies = items.filter((i) => i.type === "laboratory");

	return (
		<section className="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
			<header className="space-y-1">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Capa de validación humana
				</p>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Confirma cada estudio antes del resultado final
				</h2>
				<p className="text-xs text-zinc-500">
					v1.0 · Solo estudios de laboratorio. La IA nunca decide; el operador
					confirma, corrige o ignora.
				</p>
			</header>

			<div className="space-y-3">
				<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
					Estudios de laboratorio
				</p>
				{studies.length === 0 ? (
					<p className="text-xs text-zinc-400">Sin estudios detectados</p>
				) : (
					studies.map((item) => (
						<ItemCard
							key={item.detection_id}
							item={item}
							onConfirm={onConfirm}
							onSelectAlternative={onSelectAlternative}
							onSearch={onSearch}
							onIgnore={onIgnore}
						/>
					))
				)}
			</div>
		</section>
	);
}
