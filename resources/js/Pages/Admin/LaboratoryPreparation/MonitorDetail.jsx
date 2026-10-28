import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Field, JsonBlock, Section, StatusBadge } from "./MonitorComponents";
import { formatDate } from "./monitorPresentation";

function PresentedInstructions({ presentation }) {
	const summary = presentation.summary || {};
	const blocks = [
		...(summary.sections || []),
		...(summary.special_instructions || []),
		...(summary.individual_instructions || []),
	];
	if (presentation.has_ai_summary && blocks.length)
		return (
			<div className="space-y-4">
				{blocks.map((block, index) => (
					<article key={index} className="[overflow-wrap:anywhere]">
						<h3 className="font-medium">
							{block.title || block.study_name || "Indicaciones"}
						</h3>
						<p className="mt-2 whitespace-pre-wrap text-sm">
							{block.content}
						</p>
					</article>
				))}
			</div>
		);
	return (
		<div className="space-y-4">
			{(presentation.studies || []).map((study) => (
				<article key={study.id} className="[overflow-wrap:anywhere]">
					<h3 className="font-medium">{study.name}</h3>
					<p className="mt-2 whitespace-pre-wrap text-sm">
						{study.instructions}
					</p>
				</article>
			))}
		</div>
	);
}
export default function MonitorDetail({
	purchase,
	diagnostic,
	openAi,
	indications,
	featureFlags,
	back_url,
	history,
	labels = {},
}) {
	const presentation = indications.patient_presenter;
	return (
		<AdminLayout title={`Indicaciones IA #${purchase.id}`}>
			<div className="min-w-0 space-y-6 pb-8 text-zinc-950 [overflow-wrap:anywhere] dark:text-zinc-100">
				<header className="flex min-w-0 flex-wrap items-start justify-between gap-3">
					<div className="min-w-0">
						<Heading>Indicaciones de compra #{purchase.id}</Heading>
						<div className="mt-3 flex flex-wrap gap-2">
							<Badge
								color={
									featureFlags.deterministic_v3_enabled
										? "emerald"
										: "zinc"
								}
							>
								V3{" "}
								{featureFlags.deterministic_v3_enabled
									? "ON"
									: "OFF"}
							</Badge>
							{purchase.deleted_at && (
								<Badge color="zinc">Compra eliminada</Badge>
							)}
						</div>
					</div>
					<nav
						className="flex flex-wrap gap-2"
						aria-label="Navegación de la compra"
					>
						<Button outline href={back_url}>
							Volver al monitor
						</Button>
						{purchase.admin_url && (
							<Button href={purchase.admin_url}>
								Ver compra
							</Button>
						)}
					</nav>
				</header>
				<Section title="Información de compra">
					<dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
						<Field label="Compra">#{purchase.id}</Field>
						<Field label="Folio">{purchase.folio}</Field>
						<Field label="Laboratorio">{purchase.brand}</Field>
						<Field label="Fecha de compra">
							{formatDate(purchase.created_at)}
						</Field>
					</dl>
				</Section>
				<Section title="Qué está viendo actualmente el paciente">
					<StatusBadge value={presentation.ai_status} />
					<p className="mt-3 text-sm">
						{presentation.has_ai_summary
							? "El paciente ve el resumen vigente validado por el presenter."
							: "El paciente ve las indicaciones originales de los estudios comprados."}
					</p>
					{indications.flag_notice && (
						<p className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
							{indications.flag_notice}
						</p>
					)}
					<p className="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
						Esta vista refleja el portal actual; no acredita el
						contenido de un correo enviado previamente.
					</p>
				</Section>
				<Section title="Decisión de consolidación y revisión">
					<div className="flex flex-wrap gap-2">
						<StatusBadge value={diagnostic.decision_status} />
						{diagnostic.needs_provider_review && (
							<Badge color="amber">⚑ Revisión requerida</Badge>
						)}
					</div>
					<dl className="mt-4 grid gap-4 sm:grid-cols-2">
						<Field label="Motivo de fallback">
							{diagnostic.fallback_reason}
						</Field>
						<Field label="Categoría">
							{labels[diagnostic.fallback_category] ||
								diagnostic.fallback_category}
						</Field>
					</dl>
				</Section>
				<Section title="Resumen o indicaciones presentadas">
					<PresentedInstructions presentation={presentation} />
				</Section>
				<Section title="Estudios e instrucciones originales">
					<div className="space-y-3">
						{indications.original.map((item) => (
							<details
								key={item.id}
								className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
							>
								<summary className="cursor-pointer text-sm font-medium">
									{item.study_name} · {item.study_id}
								</summary>
								<p className="mt-3 whitespace-pre-wrap text-sm">
									{item.indications ||
										"Sin indicaciones registradas en la compra."}
								</p>
							</details>
						))}
					</div>
				</Section>
				<Section title="Reglas aplicadas">
					<div className="flex flex-wrap gap-2">
						{diagnostic.rules_applied.length ? (
							diagnostic.rules_applied.map((rule) => (
								<Badge key={rule} color="slate">
									{rule}
								</Badge>
							))
						) : (
							<p className="text-sm text-zinc-500 dark:text-zinc-400">
								Sin reglas registradas.
							</p>
						)}
					</div>
					<p className="mt-3 text-sm">
						Versión: {diagnostic.rules_version || "—"}
					</p>
					<details className="mt-4">
						<summary className="cursor-pointer text-sm font-medium">
							Requisitos estructurados
						</summary>
						<div className="mt-3">
							<JsonBlock
								value={indications.consolidated_requirements}
							/>
						</div>
					</details>
				</Section>
				<Section title="Diagnóstico técnico de OpenAI">
					<StatusBadge value={openAi.status} />
					<p className="mt-3 text-sm">{openAi.reason}</p>
					<p className="mt-2 text-sm">{openAi.call_evidence}</p>
					<dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
						<Field label="ID de ejecución">
							{openAi.ai_execution_id}
						</Field>
						<Field label="Modelo">{openAi.model}</Field>
						<Field label="Versión de prompt">
							{openAi.prompt_version}
						</Field>
						<Field label="Duración">
							{openAi.duration_ms == null
								? "—"
								: `${openAi.duration_ms} ms`}
						</Field>
						<Field label="Función">{openAi.feature}</Field>
						<Field label="Estado del resumen">
							<StatusBadge value={diagnostic.technical_status} />
						</Field>
					</dl>
					{openAi.error && (
						<p className="mt-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-950">
							{openAi.error}
						</p>
					)}
				</Section>
				<Section title="Historial disponible y datos técnicos">
					<dl className="grid gap-4 sm:grid-cols-2">
						<Field label="Resumen generado">
							{formatDate(diagnostic.generated_at)}
						</Field>
						<Field label="Resumen invalidado">
							{formatDate(diagnostic.invalidated_at)}
						</Field>
					</dl>
					<details className="mt-4">
						<summary className="cursor-pointer text-sm font-medium">
							Historial de ejecuciones (
							{history.executions.length}
							{history.has_more ? "+" : ""})
						</summary>
						<p className="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
							Hasta 20 ejecuciones persistidas, de la más reciente
							a la más antigua. No incluye Jobs sin registro de
							ejecución.
						</p>
						<ol className="mt-3 space-y-3">
							{history.executions.map((execution) => (
								<li
									key={execution.id}
									className="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800"
								>
									<p className="mb-2 font-medium">
										Ejecución #{execution.id} ·{" "}
										{formatDate(execution.created_at)}
									</p>
									<StatusBadge value={execution.status} />
									<p className="mt-2">
										{execution.feature} ·{" "}
										{execution.duration_ms ?? "—"} ms
									</p>
									{execution.error && (
										<p className="mt-2">
											{execution.error}
										</p>
									)}
								</li>
							))}
						</ol>
					</details>
					<details className="mt-4">
						<summary className="cursor-pointer text-sm font-medium">
							JSON técnico del resumen
						</summary>
						<div className="mt-3">
							<JsonBlock value={indications.summary_json} />
						</div>
					</details>
					<details className="mt-4">
						<summary className="cursor-pointer text-sm font-medium">
							Representación del paciente y diagnóstico
						</summary>
						<div className="mt-3">
							<JsonBlock
								value={{
									diagnostic,
									patient_presenter: presentation,
								}}
							/>
						</div>
					</details>
				</Section>
			</div>
		</AdminLayout>
	);
}
