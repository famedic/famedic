import { Button } from "@/Components/Catalyst/button";
import DocumentThumbnail from "./DocumentThumbnail";

function Metric({ label, value }) {
	return (
		<div className="space-y-1 text-center sm:text-left">
			<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				{label}
			</p>
			<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
				{value ?? "—"}
			</p>
		</div>
	);
}

/**
 * FASE 2 refinements — success hero (not a card), discrete entrance animation.
 */
export default function InterpretSummary({
	summary,
	previewUrl,
	fileName,
	onReviewStudies,
}) {
	return (
		<div className="interpret-success relative mx-auto max-w-lg space-y-8 py-2">
			{/* Soft success wash */}
			<div
				aria-hidden
				className="pointer-events-none absolute inset-x-0 -top-6 mx-auto h-40 max-w-sm rounded-full bg-emerald-400/10 blur-3xl dark:bg-emerald-400/15"
			/>

			<div className="relative flex flex-col items-center text-center">
				{/* Success mark — Linear/Stripe inspired */}
				<div className="interpret-success-mark relative mb-6 flex size-16 items-center justify-center">
					<span
						aria-hidden
						className="interpret-success-ring absolute inset-0 rounded-full border border-emerald-400/40"
					/>
					<span
						aria-hidden
						className="absolute inset-1 rounded-full bg-emerald-500/10 dark:bg-emerald-400/10"
					/>
					<svg
						viewBox="0 0 24 24"
						fill="none"
						className="relative size-8 text-emerald-600 dark:text-emerald-400"
						aria-hidden
					>
						<path
							d="M5 13.5 9.5 18 19 7"
							stroke="currentColor"
							strokeWidth="2.25"
							strokeLinecap="round"
							strokeLinejoin="round"
							className="interpret-success-check"
						/>
					</svg>
				</div>

				<p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-400">
					Éxito
				</p>
				<h3 className="max-w-md text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50 sm:text-[1.75rem] sm:leading-snug">
					Interpretación completada correctamente
				</h3>
				<p className="mt-3 max-w-sm text-sm leading-relaxed text-zinc-500">
					La IA leyó la receta. Revisa el resumen y confirma los estudios
					detectados antes de validar el catálogo.
				</p>
			</div>

			{previewUrl && (
				<div className="relative flex justify-center">
					<DocumentThumbnail
						previewUrl={previewUrl}
						fileName={fileName}
						size="lg"
					/>
				</div>
			)}

			{/* Metrics as open composition — not a bordered card */}
			<div className="relative grid grid-cols-2 gap-x-6 gap-y-5 border-y border-zinc-100 py-6 dark:border-zinc-800 sm:grid-cols-3">
				<Metric label="Paciente" value={summary?.patientName} />
				<Metric
					label="Estudios"
					value={
						summary?.studiesCount != null
							? String(summary.studiesCount)
							: null
					}
				/>
				<Metric
					label="Confianza"
					value={
						summary?.confidencePct != null
							? `${summary.confidencePct}%`
							: null
					}
				/>
				<Metric label="Tiempo" value={summary?.durationLabel} />
				<Metric label="Modelo" value={summary?.model} />
			</div>

			<div className="relative flex justify-center pt-1">
				<Button onClick={onReviewStudies}>Revisar estudios</Button>
			</div>

			<style>{`
				.interpret-success {
					animation: interpretSuccessIn 480ms cubic-bezier(0.22, 1, 0.36, 1);
				}
				.interpret-success-mark {
					animation: interpretMarkIn 520ms cubic-bezier(0.22, 1, 0.36, 1);
				}
				.interpret-success-ring {
					animation: interpretRing 700ms cubic-bezier(0.22, 1, 0.36, 1);
				}
				.interpret-success-check {
					stroke-dasharray: 28;
					stroke-dashoffset: 28;
					animation: interpretDrawCheck 480ms 180ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
				}
				@keyframes interpretSuccessIn {
					from { opacity: 0; transform: translateY(12px); }
					to { opacity: 1; transform: translateY(0); }
				}
				@keyframes interpretMarkIn {
					from { opacity: 0; transform: scale(0.82); }
					to { opacity: 1; transform: scale(1); }
				}
				@keyframes interpretRing {
					from { transform: scale(0.7); opacity: 0.8; }
					to { transform: scale(1.35); opacity: 0; }
				}
				@keyframes interpretDrawCheck {
					to { stroke-dashoffset: 0; }
				}
				@media (prefers-reduced-motion: reduce) {
					.interpret-success,
					.interpret-success-mark,
					.interpret-success-ring,
					.interpret-success-check {
						animation: none !important;
						stroke-dashoffset: 0 !important;
					}
				}
			`}</style>
		</div>
	);
}
