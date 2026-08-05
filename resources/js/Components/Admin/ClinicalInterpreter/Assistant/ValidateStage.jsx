import { useEffect, useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import ManualSearchDrawer from "@/Components/Admin/ClinicalInterpreter/ManualSearchDrawer";
import AiReviewCenter from "@/Components/Admin/ClinicalInterpreter/AiReview/AiReviewCenter";
import StudyConfidenceDrawer from "@/Components/Admin/ClinicalInterpreter/AiReview/StudyConfidenceDrawer";
import DocumentThumbnail from "./DocumentThumbnail";
import ValidateStudyCard from "./ValidateStudyCard";
import ValidateSummaryPanel from "./ValidateSummaryPanel";
import { buildValidationItems } from "@/Components/Admin/ClinicalInterpreter/validationHelpers";
import { summarizeValidationItems } from "./labSelection";

function cloneStudiesFromPayload(payload) {
	const matches = payload?.matches || {};
	return buildValidationItems(matches).filter((i) => i.type === "laboratory");
}

/**
 * FASE 3 — Validar estudios. Never blocks the operator.
 * FASE 6 — AI Review & Confidence Center (explain-only).
 */
export default function ValidateStage({
	interpretPayload = null,
	previewUrl = null,
	fileName = null,
	onValidationStateChange,
	onContinue,
}) {
	const [items, setItems] = useState(() =>
		cloneStudiesFromPayload(interpretPayload),
	);
	const [openAlternativesId, setOpenAlternativesId] = useState(null);
	const [searchOpen, setSearchOpen] = useState(false);
	const [searchTarget, setSearchTarget] = useState(null);
	const [explainItem, setExplainItem] = useState(null);

	useEffect(() => {
		setItems(cloneStudiesFromPayload(interpretPayload));
		setOpenAlternativesId(null);
	}, [interpretPayload]);

	const stats = useMemo(() => summarizeValidationItems(items), [items]);

	useEffect(() => {
		onValidationStateChange?.(stats.allDone, items);
	}, [stats.allDone, items, onValidationStateChange]);

	const updateItem = (detectionId, patch) => {
		setItems((prev) =>
			prev.map((item) =>
				item.detection_id === detectionId ? { ...item, ...patch } : item,
			),
		);
	};

	const handleConfirm = (item) => {
		if (!item.match) return;
		const changed =
			item.selected_catalog_id &&
			item.initial_catalog_id &&
			item.selected_catalog_id !== item.initial_catalog_id;

		updateItem(item.detection_id, {
			validation_status: changed ? "corrected" : "confirmed",
			resolution: null,
		});
		setOpenAlternativesId(null);
	};

	const handleSelectAlternative = (item, alt) => {
		updateItem(item.detection_id, {
			match: alt,
			selected_catalog_id: alt.catalog_id,
			validation_status: "pending",
			resolution: null,
		});
		setOpenAlternativesId(null);
	};

	const handleEdit = (item) => {
		updateItem(item.detection_id, {
			validation_status: "pending",
			resolution: null,
		});
		setOpenAlternativesId(null);
	};

	const handleOmit = (item) => {
		updateItem(item.detection_id, {
			validation_status: "ignored",
			resolution: "omitted",
		});
		setOpenAlternativesId(null);
	};

	const handleDefer = (item) => {
		updateItem(item.detection_id, {
			validation_status: "ignored",
			resolution: "deferred",
		});
		setOpenAlternativesId(null);
	};

	const handleManualSearch = (item) => {
		setSearchTarget({
			detection_id: item.detection_id,
			detected_name: item.detected_name,
			type: item.type || "laboratory",
		});
		setSearchOpen(true);
	};

	const handleManualSelect = (target, catalogItem) => {
		const match = {
			catalog_id: catalogItem.id,
			name: catalogItem.name,
			sku: catalogItem.sku || catalogItem.code,
			code: catalogItem.code || catalogItem.sku,
			price: catalogItem.price,
			price_cents: catalogItem.price_cents,
			delivery_time: catalogItem.delivery_time,
			laboratory: catalogItem.laboratory || catalogItem.brand,
			available: catalogItem.available,
			brand: catalogItem.brand,
			similarity: catalogItem.similarity,
			reason: catalogItem.match_reason || catalogItem.reason,
			match_status: catalogItem.match_status,
		};

		const source = items.find((i) => i.detection_id === target.detection_id);
		const wasCorrection =
			!source?.initial_catalog_id ||
			source.initial_catalog_id !== match.catalog_id;

		updateItem(target.detection_id, {
			match,
			selected_catalog_id: match.catalog_id,
			validation_status: wasCorrection ? "corrected" : "confirmed",
			resolution: null,
		});
		setSearchOpen(false);
		setSearchTarget(null);
	};

	if (!interpretPayload) {
		return (
			<div className="mx-auto max-w-md space-y-3 py-8 text-center">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					Aún no hay una interpretación
				</p>
				<p className="text-xs text-zinc-500">
					Completa la etapa Interpretar para revisar los estudios aquí.
				</p>
			</div>
		);
	}

	if (items.length === 0) {
		return (
			<div className="mx-auto max-w-md space-y-3 py-8 text-center">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					No hay estudios para validar
				</p>
				<p className="text-xs text-zinc-500">
					La interpretación no detectó estudios de laboratorio en esta receta.
				</p>
			</div>
		);
	}

	const successTitle =
		stats.included > 0 && stats.omitted === 0 && stats.deferred === 0
			? "Todos los estudios fueron validados correctamente."
			: "Ya puedes continuar con el resumen de la orden.";

	const successHint =
		stats.included === 0
			? "No hay estudios incluidos todavía. Puedes volver o generar un resumen solo con omitidos/pendientes."
			: stats.omitted > 0 || stats.deferred > 0
				? "Los omitidos y pendientes de resolución no se incluirán en la Laboratory Order."
				: "Ya puedes continuar al siguiente paso del asistente.";

	return (
		<div className="space-y-8">
			{(previewUrl || fileName) && (
				<div className="flex justify-center sm:justify-start">
					<DocumentThumbnail
						previewUrl={previewUrl}
						fileName={fileName}
						size="sm"
					/>
				</div>
			)}

			<div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_11.5rem] lg:items-start">
				<div className="space-y-3.5">
					<p className="text-xs text-zinc-500">
						Revisa cada estudio. Si no hay coincidencia, omítelo o márcalo para
						revisión: el flujo nunca se detiene.
					</p>

					{items.map((item) => (
						<ValidateStudyCard
							key={item.detection_id}
							item={item}
							alternativesOpen={openAlternativesId === item.detection_id}
							onConfirm={handleConfirm}
							onToggleAlternatives={(row) =>
								setOpenAlternativesId((id) =>
									id === row.detection_id ? null : row.detection_id,
								)
							}
							onSelectAlternative={handleSelectAlternative}
							onEdit={handleEdit}
							onOmit={handleOmit}
							onDefer={handleDefer}
							onManualSearch={handleManualSearch}
							onExplain={setExplainItem}
						/>
					))}

					{stats.allDone && (
						<div className="validate-success mt-2 space-y-4 rounded-2xl border border-emerald-200/80 bg-emerald-50/50 px-5 py-6 text-center dark:border-emerald-800/50 dark:bg-emerald-950/25">
							<p className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
								{successTitle}
							</p>
							<p className="text-xs text-zinc-500">{successHint}</p>
							{onContinue && (
								<div className="flex justify-center">
									<Button onClick={onContinue}>Continuar</Button>
								</div>
							)}
						</div>
					)}
				</div>

				<ValidateSummaryPanel
					total={stats.total}
					confirmed={stats.confirmed}
					corrected={stats.corrected}
					omitted={stats.omitted}
					deferred={stats.deferred}
					pending={stats.pending}
				/>
			</div>

			<div className="border-t border-zinc-100 pt-6 dark:border-zinc-800">
				<AiReviewCenter
					interpretPayload={interpretPayload}
					items={items}
					compact
				/>
			</div>

			<ManualSearchDrawer
				open={searchOpen}
				onClose={() => {
					setSearchOpen(false);
					setSearchTarget(null);
				}}
				target={searchTarget}
				onSelect={handleManualSelect}
			/>

			<StudyConfidenceDrawer
				open={Boolean(explainItem)}
				onClose={() => setExplainItem(null)}
				item={explainItem}
			/>

			<style>{`
				.validate-success {
					animation: validateSuccessIn 420ms cubic-bezier(0.22, 1, 0.36, 1);
				}
				@keyframes validateSuccessIn {
					from { opacity: 0; transform: translateY(8px); }
					to { opacity: 1; transform: translateY(0); }
				}
				@media (prefers-reduced-motion: reduce) {
					.validate-success { animation: none; }
				}
			`}</style>
		</div>
	);
}
