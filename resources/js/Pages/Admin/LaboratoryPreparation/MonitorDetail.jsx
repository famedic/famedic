import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";

function formatDate(value) {
	if (!value) return "—";
	return new Date(value).toLocaleString("es-MX");
}

function Field({ label, value }) {
	return (
		<div>
			<div className="text-xs font-medium uppercase tracking-wide text-zinc-500">{label}</div>
			<div className="mt-1 break-words text-sm text-zinc-950 dark:text-white">
				{value ?? "—"}
			</div>
		</div>
	);
}

function Section({ title, children }) {
	return (
		<section className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
			<Subheading>{title}</Subheading>
			<div className="mt-4">{children}</div>
		</section>
	);
}

function JsonBlock({ value }) {
	if (!value) {
		return <p className="text-sm text-zinc-500">No hay datos persistidos.</p>;
	}

	return (
		<pre className="max-h-96 overflow-auto rounded-md bg-zinc-950 p-4 text-xs text-zinc-100">
			{JSON.stringify(value, null, 2)}
		</pre>
	);
}

export default function MonitorDetail({
	purchase,
	diagnostic,
	openAi,
	indications,
	featureFlags,
}) {
	return (
		<AdminLayout title={`Indicaciones IA #${purchase.id}`}>
			<div className="space-y-6 pb-8">
				<div className="flex flex-wrap items-start justify-between gap-3">
					<div>
						<Heading>Compra #{purchase.id}</Heading>
						<div className="mt-2 flex flex-wrap gap-2">
							<Badge color="slate">{purchase.folio}</Badge>
							<Badge color="zinc">{purchase.brand}</Badge>
							<Badge color={featureFlags.deterministic_v3_enabled ? "emerald" : "zinc"}>
								V3 {featureFlags.deterministic_v3_enabled ? "ON" : "OFF"}
							</Badge>
						</div>
					</div>
					<div className="flex gap-2">
						<Button outline href={route("admin.laboratory-preparation.monitor.index")}>
							Volver
						</Button>
						<Button href={purchase.admin_url}>Ver compra</Button>
					</div>
				</div>

				{indications.flag_notice && (
					<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
						{indications.flag_notice}
					</div>
				)}

				<Section title="Datos de la orden">
					<div className="grid gap-4 md:grid-cols-4">
						<Field label="ID FAMEDIC" value={purchase.id} />
						<Field label="Folio" value={purchase.folio} />
						<Field label="Marca" value={purchase.brand} />
						<Field label="Fecha" value={formatDate(purchase.created_at)} />
					</div>
				</Section>

				<Section title="Diagnóstico de preparación">
					<div className="grid gap-4 md:grid-cols-4">
						<Field label="decision_status" value={diagnostic.decision_status} />
						<Field label="rules_version" value={diagnostic.rules_version} />
						<Field label="rules_applied" value={(diagnostic.rules_applied || []).join(", ")} />
						<Field label="fallback_reason" value={diagnostic.fallback_reason} />
						<Field label="fallback_category" value={diagnostic.fallback_category} />
						<Field label="needs_provider_review" value={diagnostic.needs_provider_review === null ? "—" : diagnostic.needs_provider_review ? "Sí" : "No"} />
						<Field label="source_hash" value={diagnostic.source_hash_short} />
						<Field label="estado técnico" value={diagnostic.technical_status} />
						<Field label="generado" value={formatDate(diagnostic.generated_at)} />
						<Field label="invalidado" value={formatDate(diagnostic.invalidated_at)} />
						<Field label="modo" value={diagnostic.mode} />
					</div>
				</Section>

				<Section title="OpenAI">
					<div className="grid gap-4 md:grid-cols-4">
						<Field label="llamado" value={openAi.called ? "Sí" : "No"} />
						<Field label="ai_execution_id" value={openAi.ai_execution_id} />
						<Field label="estado" value={openAi.status} />
						<Field label="modelo" value={openAi.model} />
						<Field label="prompt_version" value={openAi.prompt_version} />
						<Field label="duración" value={openAi.duration_ms ? `${openAi.duration_ms} ms` : null} />
						<Field label="feature" value={openAi.feature} />
						<Field label="diagnóstico" value={openAi.reason} />
					</div>
					{openAi.error && (
						<div className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-900">
							{openAi.error}
						</div>
					)}
				</Section>

				<Section title="Indicaciones originales">
					<div className="space-y-3">
						{indications.original.map((item) => (
							<details
								key={item.id}
								className="rounded-md border border-zinc-200 p-4 dark:border-zinc-800"
							>
								<summary className="cursor-pointer text-sm font-medium">
									{item.study_name} <span className="text-zinc-500">({item.study_id})</span>
								</summary>
								<p className="mt-3 whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-200">
									{item.indications || "Sin indicaciones."}
								</p>
							</details>
						))}
					</div>
				</Section>

				<Section title="Requisitos estructurados consolidados">
					<JsonBlock value={indications.consolidated_requirements} />
				</Section>

				<Section title="Resumen persistido">
					<p className="mb-4 whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-200">
						{indications.summary_text || "No hay resumen persistido."}
					</p>
					<JsonBlock value={indications.summary_json} />
				</Section>

				<Section title="Mensaje actual del presenter">
					<div className="grid gap-4 md:grid-cols-3">
						<Field label="ai_status" value={indications.patient_presenter.ai_status} />
						<Field label="source" value={indications.patient_presenter.source} />
						<Field label="texto" value={indications.patient_presenter.summary?.text} />
					</div>
					<div className="mt-4">
						<JsonBlock value={indications.patient_presenter} />
					</div>
				</Section>
			</div>
		</AdminLayout>
	);
}
