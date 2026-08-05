import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import DocumentThumbnail from "./DocumentThumbnail";

function confidenceTone(pct) {
	if (pct == null) return "zinc";
	if (pct >= 80) return "emerald";
	if (pct >= 50) return "amber";
	return "red";
}

/**
 * FASE 2 — Review detected studies only (no matching / prices).
 */
export default function InterpretStudyReview({
	studies = [],
	previewUrl,
	fileName,
	onBackToSummary,
	onDone,
}) {
	return (
		<div className="mx-auto max-w-lg space-y-6">
			{previewUrl && (
				<div className="flex justify-center sm:justify-start">
					<DocumentThumbnail
						previewUrl={previewUrl}
						fileName={fileName}
						size="sm"
					/>
				</div>
			)}

			<div className="space-y-1">
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					Estudios que detectó la IA
				</p>
				<p className="text-xs text-zinc-500">
					Sin matching todavía. En la siguiente fase conectarás cada estudio
					al catálogo Famedic.
				</p>
			</div>

			{studies.length === 0 ? (
				<p className="rounded-xl border border-dashed border-zinc-200 px-4 py-8 text-center text-sm text-zinc-400 dark:border-zinc-700">
					No se detectaron estudios en esta interpretación.
				</p>
			) : (
				<ul className="space-y-2.5">
					{studies.map((study) => {
						const pct =
							study.confidence != null &&
							!Number.isNaN(Number(study.confidence))
								? Math.round(
										Number(study.confidence) <= 1
											? Number(study.confidence) * 100
											: Number(study.confidence),
									)
								: null;

						return (
							<li
								key={study.id}
								className="flex items-start justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50/80 px-4 py-3.5 dark:border-zinc-800 dark:bg-zinc-950/40"
							>
								<div className="min-w-0">
									<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
										{study.name}
									</p>
									<p className="mt-0.5 text-[11px] text-zinc-400">
										Detectado por Vision
									</p>
								</div>
								{pct != null && (
									<Badge
										color={confidenceTone(pct)}
										className="!shrink-0 !text-[10px]"
									>
										{pct}%
									</Badge>
								)}
							</li>
						);
					})}
				</ul>
			)}

			<div className="flex flex-wrap justify-between gap-2 pt-2">
				<Button outline onClick={onBackToSummary}>
					Volver al resumen
				</Button>
				<Button onClick={onDone}>Listo</Button>
			</div>
		</div>
	);
}
