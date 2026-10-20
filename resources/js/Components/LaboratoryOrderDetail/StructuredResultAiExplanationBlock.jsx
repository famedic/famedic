import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import AiExplanationConsentDialog from "@/Components/LaboratoryOrderDetail/AiExplanationConsentDialog";
import { useLaboratoryObservationAiExplanation } from "@/hooks/useLaboratoryObservationAiExplanation";
import { AI_EXPLANATION_STATUS } from "@/lib/laboratoryAiExplanation";
import { ArrowPathIcon, InformationCircleIcon, SparklesIcon } from "@heroicons/react/24/outline";

export default function StructuredResultAiExplanationBlock({
	purchaseId,
	observationId,
	enabled = true,
}) {
	const [isAcceptingConsent, setIsAcceptingConsent] = useState(false);
	const ai = useLaboratoryObservationAiExplanation({
		purchaseId,
		observationId,
		enabled,
	});

	if (!enabled || !observationId || !ai.showAction) {
		return null;
	}

	const handleAcceptConsent = async () => {
		setIsAcceptingConsent(true);
		try {
			await ai.acceptConsent();
		} finally {
			setIsAcceptingConsent(false);
		}
	};

	const handleRequest = () => {
		void ai.requestExplanation();
	};

	return (
		<div className="mt-4 border-t border-zinc-200/80 pt-4 dark:border-slate-700/80">
			<AiExplanationConsentDialog
				isOpen={ai.consentModalOpen}
				onClose={ai.closeConsentModal}
				onAccept={handleAcceptConsent}
				isSubmitting={isAcceptingConsent}
			/>

			{ai.status === AI_EXPLANATION_STATUS.IDLE && (
				<Button
					outline
					type="button"
					className="w-full justify-center"
					onClick={handleRequest}
					aria-label="Entender este resultado con ayuda de inteligencia artificial"
				>
					<SparklesIcon className="size-4" aria-hidden="true" />
					Entender este resultado
				</Button>
			)}

			{ai.isProcessing && (
				<div
					className="flex items-start gap-2 rounded-lg bg-zinc-50 p-3 dark:bg-slate-800/60"
					aria-busy="true"
					aria-live="polite"
				>
					<ArrowPathIcon className="mt-0.5 size-4 shrink-0 animate-spin text-zinc-500" aria-hidden="true" />
					<Text className="text-sm text-zinc-700 dark:text-slate-300">
						Estamos preparando una explicación de este resultado...
					</Text>
				</div>
			)}

			{ai.status === AI_EXPLANATION_STATUS.READY && ai.explanation && (
				<div
					className="rounded-lg border border-zinc-200/80 bg-zinc-50/80 p-3 dark:border-slate-700/80 dark:bg-slate-800/40"
					aria-live="polite"
				>
					<div className="flex items-center gap-2">
						<SparklesIcon className="size-4 text-famedic-lime-dark dark:text-famedic-lime" aria-hidden="true" />
						<p className="text-sm font-semibold text-zinc-900 dark:text-white">Explicación</p>
					</div>
					<Text className="mt-2 text-sm text-zinc-700 dark:text-slate-300">{ai.explanation}</Text>
					{ai.limitations && (
						<div className="mt-3 flex items-start gap-2">
							<InformationCircleIcon
								className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400"
								aria-hidden="true"
							/>
							<Text className="text-xs text-zinc-600 dark:text-slate-400">{ai.limitations}</Text>
						</div>
					)}
				</div>
			)}

			{ai.status === AI_EXPLANATION_STATUS.FAILED && (
				<div className="space-y-2" aria-live="polite">
					<Text className="text-sm text-zinc-700 dark:text-slate-300">
						No pudimos preparar la explicación en este momento.
					</Text>
					<Button outline type="button" className="w-full justify-center" onClick={() => void ai.retry()}>
						<ArrowPathIcon className="size-4" aria-hidden="true" />
						Intentar nuevamente
					</Button>
				</div>
			)}

			{ai.status === AI_EXPLANATION_STATUS.INVALID && (
				<div className="space-y-2" aria-live="polite">
					<Text className="text-sm text-zinc-700 dark:text-slate-300">
						No fue posible generar una explicación segura para este resultado.
					</Text>
					<Button outline type="button" className="w-full justify-center" onClick={() => void ai.retry()}>
						<ArrowPathIcon className="size-4" aria-hidden="true" />
						Intentar nuevamente
					</Button>
				</div>
			)}

			{ai.status === AI_EXPLANATION_STATUS.ERROR && ai.errorMessage && (
				<div className="space-y-2" aria-live="polite">
					<Text className="text-sm text-zinc-700 dark:text-slate-300">{ai.errorMessage}</Text>
					<Button outline type="button" className="w-full justify-center" onClick={() => void ai.retry()}>
						<ArrowPathIcon className="size-4" aria-hidden="true" />
						Intentar nuevamente
					</Button>
				</div>
			)}
		</div>
	);
}
