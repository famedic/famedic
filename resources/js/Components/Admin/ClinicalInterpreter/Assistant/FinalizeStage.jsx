import { useEffect, useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import DocumentThumbnail from "./DocumentThumbnail";
import LabSelectionModal from "./LabSelectionModal";
import {
	buildCommercialContext,
	buildOrderSummaryView,
	fetchCommercialProposal,
	openClinicalOrder,
	storeClinicalOrder,
} from "./finalizeApi";

function SectionLabel({ children }) {
	return (
		<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
			{children}
		</p>
	);
}

function Divider() {
	return <div className="border-t border-zinc-100 dark:border-zinc-800" />;
}

function StudyNameList({ title, items, emptyLabel }) {
	return (
		<section className="space-y-2">
			<div className="flex items-baseline justify-between gap-3">
				<SectionLabel>{title}</SectionLabel>
				<span className="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
					{items.length}
				</span>
			</div>
			{items.length === 0 ? (
				<p className="text-xs text-zinc-400">{emptyLabel}</p>
			) : (
				<ul>
					{items.map((study) => (
						<li
							key={study.id}
							className="border-b border-zinc-100 py-2 text-sm text-zinc-700 last:border-0 dark:border-zinc-800 dark:text-zinc-200"
						>
							{study.name}
							{study.laboratory && (
								<span className="mt-0.5 block text-xs text-zinc-400">
									{study.laboratory}
									{study.price ? ` · ${study.price}` : ""}
								</span>
							)}
						</li>
					))}
				</ul>
			)}
		</section>
	);
}

/**
 * FASE 4 — Resumen de la Orden + lab selection modal before generate.
 */
export default function FinalizeStage({
	interpretPayload = null,
	validatedItems = [],
	previewUrl = null,
	fileName = null,
}) {
	const interpretation = interpretPayload?.interpretation || null;
	const metrics = interpretPayload?.interpretation_metrics || null;
	const documentMeta = interpretPayload?.document || null;

	const [workingItems, setWorkingItems] = useState(validatedItems);
	const [proposal, setProposal] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [generating, setGenerating] = useState(false);
	const [generateError, setGenerateError] = useState(null);
	const [labModalOpen, setLabModalOpen] = useState(false);

	useEffect(() => {
		setWorkingItems(validatedItems);
	}, [validatedItems]);

	const context = useMemo(
		() =>
			buildCommercialContext({
				interpretation,
				validatedItems: workingItems,
				documentMeta,
				metrics,
			}),
		[interpretation, workingItems, documentMeta, metrics],
	);

	useEffect(() => {
		let cancelled = false;

		const load = async () => {
			if (!workingItems?.length) {
				setLoading(false);
				setError("No hay estudios para armar el resumen.");
				return;
			}

			setLoading(true);
			setError(null);
			const result = await fetchCommercialProposal(context);
			if (cancelled) return;

			if (!result.ok) {
				setProposal(null);
				setError(result.message);
				setLoading(false);
				return;
			}

			setProposal(result.data.proposal || null);
			setLoading(false);
		};

		load();
		return () => {
			cancelled = true;
		};
	}, [context, workingItems]);

	const view = useMemo(
		() =>
			buildOrderSummaryView({
				proposal,
				interpretation,
				validatedItems: workingItems,
			}),
		[proposal, interpretation, workingItems],
	);

	const generateWithItems = async (items) => {
		setGenerating(true);
		setGenerateError(null);
		setLabModalOpen(false);

		const nextContext = buildCommercialContext({
			interpretation,
			validatedItems: items,
			documentMeta,
			metrics,
		});

		const result = await storeClinicalOrder(nextContext);
		if (!result.ok) {
			setGenerateError(result.message);
			setGenerating(false);
			return;
		}

		const uuid = result.data.clinical_order?.uuid;
		if (!uuid) {
			setGenerateError(
				"La orden se guardó, pero no encontramos el expediente. Revísala en el listado.",
			);
			setGenerating(false);
			return;
		}

		openClinicalOrder(uuid);
	};

	const handleLabConfirm = async ({ items }) => {
		setWorkingItems(items);
		await generateWithItems(items);
	};

	if (!interpretPayload || !validatedItems?.length) {
		return (
			<div className="mx-auto max-w-md space-y-3 py-8 text-center">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					Aún no hay una orden para resumir
				</p>
				<p className="text-xs text-zinc-500">
					Completa Interpretar y Validar antes de generar la Laboratory Order.
				</p>
			</div>
		);
	}

	return (
		<div className="mx-auto max-w-lg space-y-8">
			{(previewUrl || fileName) && (
				<div className="flex justify-center sm:justify-start">
					<DocumentThumbnail
						previewUrl={previewUrl}
						fileName={fileName}
						size="sm"
					/>
				</div>
			)}

			<header className="space-y-2">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Resumen de la Orden
				</p>
				<p className="text-sm leading-relaxed text-zinc-500">
					Revisa incluidos, omitidos y pendientes. Luego elige cómo construir la
					Laboratory Order.
				</p>
			</header>

			{loading ? (
				<div className="space-y-4 py-4" aria-busy="true">
					{[1, 2, 3, 4].map((i) => (
						<div
							key={i}
							className="h-3 animate-pulse rounded-full bg-zinc-100 dark:bg-zinc-800"
							style={{ width: `${70 - i * 8}%` }}
						/>
					))}
				</div>
			) : error ? (
				<div className="space-y-4 py-2 text-center">
					<p className="text-sm text-zinc-600 dark:text-zinc-300">{error}</p>
					<Button
						outline
						onClick={async () => {
							setLoading(true);
							setError(null);
							const result = await fetchCommercialProposal(context);
							if (!result.ok) {
								setError(result.message);
								setLoading(false);
								return;
							}
							setProposal(result.data.proposal || null);
							setLoading(false);
						}}
					>
						Intentar nuevamente
					</Button>
				</div>
			) : (
				<div className="space-y-7">
					<section className="space-y-1.5">
						<SectionLabel>Paciente</SectionLabel>
						<p className="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
							{view.patientName || "Sin nombre en la receta"}
						</p>
						{(view.patientAge != null || view.patientSex) && (
							<p className="text-xs text-zinc-400">
								{[
									view.patientAge != null ? `${view.patientAge} años` : null,
									view.patientSex,
								]
									.filter(Boolean)
									.join(" · ")}
							</p>
						)}
					</section>

					<Divider />

					<section className="grid grid-cols-3 gap-3 text-center">
						<div>
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Incluidos
							</p>
							<p className="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{view.counts.included}
							</p>
						</div>
						<div>
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Omitidos
							</p>
							<p className="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{view.counts.omitted}
							</p>
						</div>
						<div>
							<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
								Pendientes
							</p>
							<p className="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
								{view.counts.pending}
							</p>
						</div>
					</section>

					<Divider />

					<StudyNameList
						title="Estudios incluidos"
						items={view.studies}
						emptyLabel="Ningún estudio se incluirá en la orden."
					/>

					{(view.omittedStudies.length > 0 || view.pendingStudies.length > 0) && (
						<>
							<Divider />
							{view.omittedStudies.length > 0 && (
								<StudyNameList
									title="Estudios omitidos"
									items={view.omittedStudies}
									emptyLabel=""
								/>
							)}
							{view.pendingStudies.length > 0 && (
								<StudyNameList
									title="Estudios pendientes"
									items={view.pendingStudies}
									emptyLabel=""
								/>
							)}
						</>
					)}

					{view.canGenerateOrder && (
						<>
							<Divider />

							<section className="space-y-3">
								<div className="flex items-baseline justify-between gap-4">
									<span className="text-sm text-zinc-500">
										Laboratorios participantes
									</span>
									<span className="max-w-[55%] text-right text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{view.participatingLabs.length
											? view.participatingLabs.join(", ")
											: "—"}
									</span>
								</div>
								<div className="flex items-baseline justify-between gap-4">
									<span className="text-sm text-zinc-500">Tiempo estimado</span>
									<span className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{view.estimatedTime || "Según laboratorio"}
									</span>
								</div>
								<div className="flex items-baseline justify-between gap-4">
									<span className="text-sm text-zinc-500">
										Paquetes encontrados
									</span>
									<span className="text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-50">
										{view.packagesCount}
									</span>
								</div>
								<div className="flex items-baseline justify-between gap-4">
									<span className="text-sm text-zinc-500">Ahorro</span>
									<span className="text-sm font-medium tabular-nums text-emerald-700 dark:text-emerald-400">
										{view.savingsLabel || "—"}
									</span>
								</div>
							</section>

							<Divider />

							<section className="flex items-baseline justify-between gap-4">
								<span className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
									Precio total
								</span>
								<span className="text-xl font-semibold tracking-tight tabular-nums text-zinc-900 dark:text-zinc-50">
									{view.total || "—"}
								</span>
							</section>
						</>
					)}

					<div className="space-y-3 pt-2">
						{generateError && (
							<p className="text-center text-sm text-amber-800 dark:text-amber-300">
								{generateError}
							</p>
						)}
						{!view.canGenerateOrder && (
							<p className="text-center text-sm text-zinc-500">
								No hay estudios incluidos. Vuelve a Validar para confirmar al
								menos uno, o deja pendientes/omitidos según el piloto.
							</p>
						)}
						<div className="flex justify-center">
							<Button
								disabled={generating || !view.canGenerateOrder}
								onClick={() => setLabModalOpen(true)}
								className="!text-sm"
							>
								{generating
									? "Generando expediente…"
									: "Generar Laboratory Order"}
							</Button>
						</div>
						<p className="text-center text-[11px] text-zinc-400">
							Antes de crear el expediente te pediremos cómo elegir el
							laboratorio.
						</p>
					</div>
				</div>
			)}

			<LabSelectionModal
				open={labModalOpen}
				onClose={() => !generating && setLabModalOpen(false)}
				validatedItems={workingItems}
				busy={generating}
				onConfirm={handleLabConfirm}
			/>
		</div>
	);
}
