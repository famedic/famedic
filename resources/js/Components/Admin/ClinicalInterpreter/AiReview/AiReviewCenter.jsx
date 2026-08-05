import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import InterpretationQualityCard from "./InterpretationQualityCard";
import InterpretationFindingsCard from "./InterpretationFindingsCard";
import LearningInsightsCard from "./LearningInsightsCard";
import DecisionHistoryTimeline from "./DecisionHistoryTimeline";
import StudyConfidenceDrawer from "./StudyConfidenceDrawer";
import TechnicalDetailsDrawer from "./TechnicalDetailsDrawer";
import ConfidenceBadge from "./ConfidenceBadge";
import {
	buildDecisionHistory,
	buildFindings,
	buildLearningInsights,
	buildQualitySnapshot,
	normalizePersistedItem,
} from "./confidenceHelpers";

/**
 * AI Review & Confidence Center — explain-only layer.
 * Works from live validation items and/or persisted Clinical Order.
 */
export default function AiReviewCenter({
	interpretPayload = null,
	items = null,
	order = null,
	showDecisionHistory = false,
	showStudyList = false,
	compact = false,
}) {
	const [studyOpen, setStudyOpen] = useState(false);
	const [activeStudy, setActiveStudy] = useState(null);
	const [techOpen, setTechOpen] = useState(false);

	const resolvedItems = useMemo(() => {
		if (Array.isArray(items) && items.length > 0) return items;
		if (order?.validation?.items?.length) {
			return order.validation.items.map(normalizePersistedItem);
		}
		if (order?.studies?.length) {
			return order.studies.map((s, idx) =>
				normalizePersistedItem({
					detection_id: s.laboratory_test_id || `study-${idx}`,
					detected_name: s.detected_name || s.name,
					name: s.name,
					validation_status: "confirmed",
					match: {
						name: s.name,
						laboratory: s.laboratory,
						catalog_id: s.laboratory_test_id,
						similarity: null,
						reason: null,
					},
				}),
			);
		}
		return [];
	}, [items, order]);

	const quality = useMemo(
		() =>
			buildQualitySnapshot({
				items: resolvedItems,
				interpretPayload,
				order,
			}),
		[resolvedItems, interpretPayload, order],
	);

	const findings = useMemo(
		() =>
			buildFindings({
				items: resolvedItems,
				interpretPayload,
				order,
			}),
		[resolvedItems, interpretPayload, order],
	);

	const learning = useMemo(
		() => buildLearningInsights({ items: resolvedItems, order }),
		[resolvedItems, order],
	);

	const history = useMemo(
		() => (showDecisionHistory && order ? buildDecisionHistory(order) : []),
		[showDecisionHistory, order],
	);

	const openStudy = (item) => {
		setActiveStudy(item);
		setStudyOpen(true);
	};

	return (
		<div className={compact ? "space-y-4" : "space-y-5"}>
			<div className="flex flex-wrap items-center justify-between gap-2">
				<div>
					<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Transparencia
					</p>
					<h2 className="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
						AI Review & Confidence Center
					</h2>
					<p className="mt-0.5 text-xs text-zinc-500">
						Explica qué vio la IA y por qué eligió cada estudio — no modifica el
						flujo.
					</p>
				</div>
				<Button
					plain
					className="!text-xs text-zinc-500"
					onClick={() => setTechOpen(true)}
				>
					Detalles técnicos
				</Button>
			</div>

			<InterpretationQualityCard quality={quality} />
			<InterpretationFindingsCard findings={findings} />
			<LearningInsightsCard signals={learning} />

			{showStudyList && resolvedItems.length > 0 && (
				<section className="space-y-2">
					<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Por qué eligió cada estudio
					</p>
					<ul className="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
						{resolvedItems.map((item) => (
							<li
								key={item.detection_id}
								className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-3"
							>
								<div className="min-w-0">
									<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{item.match?.name || item.name || item.detected_name}
									</p>
									<p className="truncate text-xs text-zinc-400">
										Detectado · {item.detected_name || "—"}
									</p>
								</div>
								<div className="flex flex-wrap items-center gap-2">
									<ConfidenceBadge item={item} />
									<Button
										plain
										className="!text-xs"
										onClick={() => openStudy(item)}
									>
										Ver por qué
									</Button>
								</div>
							</li>
						))}
					</ul>
				</section>
			)}

			{showDecisionHistory && <DecisionHistoryTimeline steps={history} />}

			<StudyConfidenceDrawer
				open={studyOpen}
				onClose={() => {
					setStudyOpen(false);
					setActiveStudy(null);
				}}
				item={activeStudy}
			/>

			<TechnicalDetailsDrawer
				open={techOpen}
				onClose={() => setTechOpen(false)}
				interpretPayload={interpretPayload}
				order={order}
			/>
		</div>
	);
}

export { StudyConfidenceDrawer, ConfidenceBadge };
