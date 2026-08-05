import { useCallback, useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import AssistantShell from "@/Components/Admin/ClinicalInterpreter/Assistant/AssistantShell";
import AiDetailsDrawer from "@/Components/Admin/ClinicalInterpreter/Assistant/AiDetailsDrawer";
import InterpretStage from "@/Components/Admin/ClinicalInterpreter/Assistant/InterpretStage";
import ValidateStage from "@/Components/Admin/ClinicalInterpreter/Assistant/ValidateStage";
import FinalizeStage from "@/Components/Admin/ClinicalInterpreter/Assistant/FinalizeStage";
import {
	PRODUCT_SCOPE,
	STAGE_INDEX,
	WIZARD_STAGES,
} from "@/Components/Admin/ClinicalInterpreter/productScope";

/**
 * Asistente — FASE 1–4 (Interpretar → Validar → Resumen de la Orden).
 */
export default function Assistant() {
	const [stageId, setStageId] = useState("interpret");
	const [aiOpen, setAiOpen] = useState(false);
	const [interpretDone, setInterpretDone] = useState(false);
	const [validateDone, setValidateDone] = useState(false);
	const [session, setSession] = useState(null);

	const stageIndex = STAGE_INDEX[stageId] ?? 0;

	const completedStageIds = useMemo(() => {
		const fromIndex = WIZARD_STAGES.filter((_, i) => i < stageIndex).map(
			(s) => s.id,
		);
		const extra = [];
		if (
			interpretDone &&
			stageId === "interpret" &&
			!fromIndex.includes("interpret")
		) {
			extra.push("interpret");
		}
		if (
			validateDone &&
			stageId === "validate" &&
			!fromIndex.includes("validate")
		) {
			extra.push("validate");
		}
		return [...fromIndex, ...extra];
	}, [stageIndex, interpretDone, validateDone, stageId]);

	const goBack = () => {
		if (stageIndex <= 0) return;
		setStageId(WIZARD_STAGES[stageIndex - 1].id);
	};

	const goContinue = () => {
		if (stageIndex >= WIZARD_STAGES.length - 1) return;
		if (stageId === "interpret" && !interpretDone) return;
		if (stageId === "validate" && !validateDone) return;
		setStageId(WIZARD_STAGES[stageIndex + 1].id);
	};

	const continueDisabled =
		stageId === "interpret"
			? !interpretDone
			: stageId === "validate"
				? !validateDone
				: false;

	const handleValidationStateChange = useCallback((allDone, items) => {
		setValidateDone(Boolean(allDone));
		if (Array.isArray(items)) {
			setSession((prev) => ({
				...(prev || {}),
				validatedItems: items,
			}));
		}
	}, []);

	return (
		<AdminLayout title={PRODUCT_SCOPE.productName}>
			<AssistantShell
				stageId={stageId}
				completedStageIds={completedStageIds}
				onStageChange={setStageId}
				onBack={goBack}
				onContinue={
					stageIndex >= WIZARD_STAGES.length - 1 ? undefined : goContinue
				}
				showContinue={stageIndex < WIZARD_STAGES.length - 1}
				continueDisabled={continueDisabled}
				continueLabel="Continuar"
				onOpenAiDetails={() => setAiOpen(true)}
			>
				{stageId === "interpret" ? (
					<InterpretStage
						onSessionReady={(payload, summary, previewUrl) => {
							setSession({
								payload,
								summary,
								previewUrl: previewUrl || null,
								validatedItems: [],
							});
							setValidateDone(false);
						}}
						onInterpretComplete={(payload, summary) => {
							setSession((prev) => ({
								payload: payload || prev?.payload || null,
								summary: summary || prev?.summary || null,
								previewUrl: prev?.previewUrl || null,
								validatedItems: [],
							}));
							setInterpretDone(true);
							setValidateDone(false);
						}}
					/>
				) : stageId === "validate" ? (
					<ValidateStage
						interpretPayload={session?.payload || null}
						previewUrl={session?.previewUrl || null}
						onValidationStateChange={handleValidationStateChange}
						onContinue={goContinue}
					/>
				) : (
					<FinalizeStage
						interpretPayload={session?.payload || null}
						validatedItems={session?.validatedItems || []}
						previewUrl={session?.previewUrl || null}
					/>
				)}
			</AssistantShell>

			<AiDetailsDrawer
				open={aiOpen}
				onClose={() => setAiOpen(false)}
				interpretPayload={session?.payload || null}
			/>
		</AdminLayout>
	);
}
