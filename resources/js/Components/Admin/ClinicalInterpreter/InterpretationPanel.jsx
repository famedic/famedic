import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import {
	confidenceLabel,
	detectionStatusMeta,
} from "@/Components/Admin/ClinicalInterpreter/matchingHelpers";

function FieldBlock({ label, value, confidence, status }) {
	const meta = detectionStatusMeta(status);

	return (
		<div className="rounded-lg border border-zinc-100 bg-zinc-50/80 p-3 dark:border-zinc-800 dark:bg-zinc-950/40">
			<div className="mb-1.5 flex flex-wrap items-center gap-1.5">
				<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
					{label}
				</p>
				<Badge color="zinc" className="!text-[10px]">
					{confidenceLabel(confidence)}
				</Badge>
				<Badge color={meta.color} className="!text-[10px]">
					{meta.label}
				</Badge>
			</div>
			<p className="text-sm text-zinc-800 dark:text-zinc-100">{value || "—"}</p>
		</div>
	);
}

function ListBlock({ label, items }) {
	return (
		<div className="space-y-2">
			<p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
				{label}
			</p>
			{items.map((item) => {
				const meta = detectionStatusMeta(item.status);
				return (
					<div
						key={item.id}
						className="rounded-lg border border-zinc-100 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900"
					>
						<div className="flex flex-wrap items-start justify-between gap-2">
							<div>
								<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{item.detected_name}
								</p>
								{(item.dose || item.frequency) && (
									<p className="mt-0.5 text-xs text-zinc-500">
										{[item.dose, item.frequency, item.duration]
											.filter(Boolean)
											.join(" · ")}
									</p>
								)}
							</div>
							<div className="flex flex-wrap gap-1">
								<Badge color="zinc" className="!text-[10px]">
									{confidenceLabel(item.confidence)}
								</Badge>
								<Badge color={meta.color} className="!text-[10px]">
									{meta.label}
								</Badge>
							</div>
						</div>
					</div>
				);
			})}
		</div>
	);
}

export default function InterpretationPanel({ interpretation }) {
	if (!interpretation) {
		return null;
	}

	return (
		<section className="flex h-full min-h-[320px] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<header className="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
				<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Panel 2
				</p>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Interpretación IA
				</h2>
				<Text className="mt-1 !text-xs text-zinc-500">
					Solo lectura estructurada · la IA no decide el catálogo
				</Text>
			</header>

			<div className="flex-1 space-y-3 overflow-y-auto p-4">
				<FieldBlock
					label="Paciente"
					value={interpretation.patient?.name}
					confidence={interpretation.patient?.confidence}
					status={interpretation.patient?.status}
				/>
				<FieldBlock
					label="Médico"
					value={[
						interpretation.physician?.name,
						interpretation.physician?.license,
					]
						.filter(Boolean)
						.join(" · ")}
					confidence={interpretation.physician?.confidence}
					status={interpretation.physician?.status}
				/>
				<FieldBlock
					label="Fecha"
					value={interpretation.date?.value}
					confidence={interpretation.date?.confidence}
					status={interpretation.date?.status}
				/>
				<FieldBlock
					label="Diagnóstico"
					value={interpretation.diagnosis?.value}
					confidence={interpretation.diagnosis?.confidence}
					status={interpretation.diagnosis?.status}
				/>
				<ListBlock
					label="Estudios detectados"
					items={interpretation.studies || []}
				/>
				<FieldBlock
					label="Indicaciones"
					value={interpretation.indications?.value}
					confidence={interpretation.indications?.confidence}
					status={interpretation.indications?.status}
				/>
				<FieldBlock
					label="Observaciones"
					value={interpretation.observations?.value}
					confidence={interpretation.observations?.confidence}
					status={interpretation.observations?.status}
				/>
			</div>
		</section>
	);
}
