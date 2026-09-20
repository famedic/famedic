import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import {
	formatStructuredObservationValue,
	formatStructuredReference,
	getStructuredObservationStatusPresentation,
} from "@/lib/laboratoryStructuredPatientResults";
import StructuredResultAiExplanationBlock from "@/Components/LaboratoryOrderDetail/StructuredResultAiExplanationBlock";
import {
	CheckCircleIcon,
	ExclamationTriangleIcon,
	InformationCircleIcon,
} from "@heroicons/react/24/outline";

function StatusIcon({ icon }) {
	if (icon === "check") {
		return <CheckCircleIcon className="size-4 shrink-0 text-green-600 dark:text-green-400" aria-hidden="true" />;
	}

	if (icon === "warning") {
		return (
			<ExclamationTriangleIcon className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
		);
	}

	return <InformationCircleIcon className="size-4 shrink-0 text-zinc-500 dark:text-slate-400" aria-hidden="true" />;
}

export default function StructuredResultObservationRow({
	observation,
	purchaseId = null,
	aiExplanationEnabled = false,
}) {
	const analyteName = observation?.analyte?.name || "Análito";
	const valueLabel = formatStructuredObservationValue(observation);
	const referenceLabel = formatStructuredReference(observation?.reference, observation?.unit);
	const presentation = getStructuredObservationStatusPresentation(
		observation?.status,
		observation?.abnormal,
	);

	return (
		<article
			className="rounded-xl border border-zinc-200/80 bg-white p-4 dark:border-slate-700/80 dark:bg-slate-900/40"
			aria-label={`Resultado de ${analyteName}`}
		>
			<h4 className="text-sm font-semibold text-zinc-900 dark:text-white">{analyteName}</h4>
			<p className="mt-1 text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">{valueLabel}</p>

			{presentation.showIndicator && presentation.label && (
				<div className="mt-2 flex flex-wrap items-center gap-2">
					<StatusIcon icon={presentation.icon} />
					<Badge color={presentation.badgeColor}>{presentation.label}</Badge>
				</div>
			)}

			{referenceLabel && (
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					Referencia: <Strong>{referenceLabel}</Strong>
				</Text>
			)}

			<StructuredResultAiExplanationBlock
				purchaseId={purchaseId}
				observationId={observation?.id}
				enabled={aiExplanationEnabled}
			/>
		</article>
	);
}
