import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import StructuredResultObservationRow from "@/Components/LaboratoryOrderDetail/StructuredResultObservationRow";
import { STRUCTURED_RESULTS_FETCH_STATE } from "@/lib/laboratoryStructuredPatientResults";
import { ArrowPathIcon } from "@heroicons/react/24/outline";

function StructuredResultsSkeleton() {
	return (
		<div className="space-y-3" aria-busy="true" aria-live="polite" aria-label="Cargando resultados estructurados">
			{[1, 2, 3].map((item) => (
				<div
					key={item}
					className="animate-pulse rounded-xl border border-zinc-200/80 bg-zinc-50 p-4 dark:border-slate-700/80 dark:bg-slate-900/30"
				>
					<div className="h-4 w-1/3 rounded bg-zinc-200 dark:bg-slate-700" />
					<div className="mt-3 h-6 w-1/2 rounded bg-zinc-200 dark:bg-slate-700" />
					<div className="mt-3 h-4 w-2/5 rounded bg-zinc-200 dark:bg-slate-700" />
				</div>
			))}
		</div>
	);
}

function formatReportDate(value) {
	if (!value) return null;

	const parsed = new Date(value);
	if (Number.isNaN(parsed.getTime())) return null;

	return parsed.toLocaleDateString("es-MX", {
		day: "numeric",
		month: "long",
		year: "numeric",
	});
}

export default function StructuredResultsPanel({
	status,
	data = null,
	onRetry,
	purchaseId = null,
}) {
	const aiExplanationEnabled = Boolean(data?.meta?.ai_explanation_enabled);
	if (status === STRUCTURED_RESULTS_FETCH_STATE.IDLE || status === STRUCTURED_RESULTS_FETCH_STATE.OTP_PENDING) {
		return null;
	}

	if (status === STRUCTURED_RESULTS_FETCH_STATE.LOADING) {
		return (
			<section aria-labelledby="structured-results-heading">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				<div className="mt-3">
					<StructuredResultsSkeleton />
				</div>
			</section>
		);
	}

	if (status === STRUCTURED_RESULTS_FETCH_STATE.UNAVAILABLE) {
		return (
			<section aria-labelledby="structured-results-heading">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					Los resultados estructurados aún no están disponibles.
				</Text>
			</section>
		);
	}

	if (status === STRUCTURED_RESULTS_FETCH_STATE.UNAUTHORIZED) {
		return (
			<section aria-labelledby="structured-results-heading">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					Inicia sesión para consultar tus resultados estructurados.
				</Text>
			</section>
		);
	}

	if (status === STRUCTURED_RESULTS_FETCH_STATE.FORBIDDEN) {
		return (
			<section aria-labelledby="structured-results-heading">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					No tienes acceso a estos resultados.
				</Text>
			</section>
		);
	}

	if (status === STRUCTURED_RESULTS_FETCH_STATE.ERROR) {
		return (
			<section aria-labelledby="structured-results-heading">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					No pudimos cargar tus resultados estructurados. Intenta de nuevo en unos momentos.
				</Text>
				{typeof onRetry === "function" && (
					<Button outline type="button" className="mt-3 w-full justify-center" onClick={onRetry}>
						<ArrowPathIcon className="size-4" />
						Reintentar
					</Button>
				)}
			</section>
		);
	}

	const observations = Array.isArray(data?.observations) ? data.observations : [];
	const reportDate =
		formatReportDate(data?.report?.reported_at) ||
		formatReportDate(data?.report?.specimen_collected_at);

	return (
		<section aria-labelledby="structured-results-heading">
			<div className="flex flex-wrap items-baseline justify-between gap-2">
				<h4 id="structured-results-heading" className="text-sm font-semibold text-zinc-900 dark:text-white">
					Resultados estructurados
				</h4>
				{reportDate && (
					<Text className="text-xs text-zinc-500 dark:text-slate-400">{reportDate}</Text>
				)}
			</div>

			{observations.length === 0 ? (
				<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
					Los resultados estructurados aún no están disponibles.
				</Text>
			) : (
				<ul className="mt-3 space-y-3" role="list">
					{observations.map((observation, index) => (
						<li
							key={
								observation?.id ??
								`${observation?.analyte?.code || observation?.analyte?.name || "obs"}-${index}`
							}
						>
							<StructuredResultObservationRow
								observation={observation}
								purchaseId={purchaseId}
								aiExplanationEnabled={aiExplanationEnabled}
							/>
						</li>
					))}
				</ul>
			)}
		</section>
	);
}
