import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";

const OBJECTIVES = ["promocion", "educacion", "lanzamiento", "temporada"];
const TONES = ["cercano", "profesional", "preventivo", "comercial"];
const FIELDS = [
	["eyebrow", "Texto superior"],
	["title", "Título"],
	["subtitle", "Subtítulo"],
	["description", "Descripción"],
	["primary_cta_label", "CTA principal"],
	["secondary_cta_label", "CTA secundario"],
	["editorial_title", "Título editorial"],
	["editorial_body", "Cuerpo editorial"],
];

const EMPTY_SUGGESTION = {
	eyebrow: "",
	title: "",
	subtitle: "",
	description: "",
	primary_cta_label: "",
	secondary_cta_label: "",
	editorial_title: "",
	editorial_body: "",
	editorial_items: [],
};

function csrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

function currentValue(data, key) {
	const map = {
		title: "public_title",
		subtitle: "public_subtitle",
		description: "public_description",
		editorial_title: "editorial_title",
		editorial_body: "editorial_body",
	};
	return data[map[key] || key] || "";
}

function applyFieldName(key) {
	return {
		title: "public_title",
		subtitle: "public_subtitle",
		description: "public_description",
	}[key] || key;
}

async function readJson(response) {
	const text = await response.text();
	if (!text.trim()) {
		return null;
	}

	try {
		return JSON.parse(text);
	} catch {
		return { success: false, error: { message: "El servidor devolvió una respuesta inválida." } };
	}
}

function responseMessage(response, payload) {
	if (payload?.error?.message) return payload.error.message;
	if (payload?.message) return payload.message;

	return {
		401: "Tu sesión no está activa. Inicia sesión de nuevo.",
		403: "No tienes permiso para generar sugerencias.",
		419: "La sesión expiró. Actualiza la página antes de intentar de nuevo.",
		422: "Revisa los datos del asistente.",
		429: "Se alcanzó el límite de uso. Intenta de nuevo en unos minutos.",
		500: "No fue posible generar sugerencias. Revisa la configuración de IA.",
	}[response.status] || "No fue posible generar sugerencias.";
}

function normalizeSuggestion(payload) {
	const data = payload?.data || payload?.suggestion || payload;

	return {
		...EMPTY_SUGGESTION,
		...(data || {}),
		editorial_items: Array.isArray(data?.editorial_items)
			? data.editorial_items
			: [],
	};
}

export default function MarketingCampaignAiContentAssistant({ data, onApply }) {
	const [open, setOpen] = useState(false);
	const [context, setContext] = useState("");
	const [objective, setObjective] = useState("promocion");
	const [tone, setTone] = useState("profesional");
	const [audience, setAudience] = useState("");
	const [status, setStatus] = useState("idle");
	const [error, setError] = useState("");
	const [suggestion, setSuggestion] = useState(null);

	const generate = async () => {
		if (status === "enviando" || status === "generando") return;
		setError("");
		setStatus("enviando");

		try {
			const response = await fetch(route("admin.marketing-campaigns.ai.landing-content"), {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrfToken(),
				},
				body: JSON.stringify({ context, objective, tone, audience }),
			});

			setStatus("generando");
			const payload = await readJson(response);
			if (!response.ok || payload?.success === false) {
				setStatus(response.status === 429 ? "rate_limited" : "error");
				setError(responseMessage(response, payload));
				return;
			}

			setSuggestion(normalizeSuggestion(payload));
			setStatus("listo");
		} catch {
			setStatus("error");
			setError("No se pudo conectar con el asistente.");
		} finally {
			if (status === "enviando" || status === "generando") {
				setStatus((current) =>
					current === "enviando" || current === "generando" ? "idle" : current,
				);
			}
		}
	};

	const applyOne = (key) => {
		if (!suggestion) return;
		onApply(applyFieldName(key), suggestion[key] || "");
	};

	const applyAll = () => {
		if (!suggestion) return;
		const patch = {};
		for (const [key] of FIELDS) {
			const target = applyFieldName(key);
			if (!currentValue(data, key) && suggestion[key]) {
				patch[target] = suggestion[key];
			}
		}
		if (Array.isArray(suggestion.editorial_items) && !data.editorial_items?.length) {
			patch.editorial_items = suggestion.editorial_items.map((item) => ({
				title: item.title,
				description: item.description,
				icon: item.icon_key,
			}));
		}
		onApply(patch);
	};

	return (
		<div className="rounded-xl border border-lime-200 bg-lime-50 p-4 dark:border-lime-900 dark:bg-lime-950/30">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div>
					<Text className="font-semibold">Crear contenido con IA</Text>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
						La IA genera sugerencias; tú decides qué aplicar antes de guardar.
					</Text>
				</div>
				<Button type="button" outline onClick={() => setOpen(true)}>
					Crear contenido con IA
				</Button>
			</div>

			{open && (
				<div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/70 p-4">
					<div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl dark:bg-zinc-950">
						<div className="flex flex-wrap items-center justify-between gap-3">
							<Text className="text-lg font-semibold">Sugerencias de contenido</Text>
							<Button type="button" outline onClick={() => setOpen(false)}>
								Cerrar
							</Button>
						</div>
						<div className="mt-5 grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]">
							<div className="space-y-4">
								<Field>
									<Label>Qué quieres comunicar</Label>
									<Textarea rows={6} value={context} onChange={(event) => setContext(event.target.value)} />
									{context && context.length < 20 && <ErrorMessage>Agrega más contexto.</ErrorMessage>}
								</Field>
								<Field>
									<Label>Objetivo</Label>
									<div className="mt-2 flex flex-wrap gap-2">
										{OBJECTIVES.map((item) => (
											<Button key={item} type="button" outline={objective !== item} onClick={() => setObjective(item)}>
												{item}
											</Button>
										))}
									</div>
								</Field>
								<Field>
									<Label>Tono</Label>
									<div className="mt-2 flex flex-wrap gap-2">
										{TONES.map((item) => (
											<Button key={item} type="button" outline={tone !== item} onClick={() => setTone(item)}>
												{item}
											</Button>
										))}
									</div>
								</Field>
								<Field>
									<Label>Audiencia opcional</Label>
									<Input value={audience} onChange={(event) => setAudience(event.target.value)} />
								</Field>
								<Button type="button" color="lime" disabled={context.length < 20 || status === "enviando" || status === "generando"} onClick={generate}>
									{status === "enviando" || status === "generando" ? "Generando sugerencias..." : "Generar sugerencias"}
								</Button>
								<Text className="text-xs text-zinc-500">
									Revisa las sugerencias antes de publicar. La IA puede cometer errores.
								</Text>
								{error && (
									<div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30">
										<Text className="text-sm text-red-700 dark:text-red-300">{error}</Text>
										<Button type="button" outline className="mt-3" onClick={generate}>
											Reintentar
										</Button>
									</div>
								)}
							</div>
							<div className="space-y-4">
								{suggestion ? (
									<>
										<div className="flex flex-wrap justify-end gap-2">
											<Button type="button" outline onClick={generate}>Regenerar</Button>
											<Button type="button" color="lime" onClick={applyAll}>Aplicar campos vacíos</Button>
										</div>
										<div className="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
											{FIELDS.map(([key, label]) => (
												<div key={key} className="grid gap-3 border-b border-zinc-100 p-3 last:border-b-0 dark:border-zinc-800 md:grid-cols-[1fr_1fr_auto]">
													<div>
														<Text className="text-xs font-semibold uppercase text-zinc-500">{label} actual</Text>
														<Text className="mt-1 text-sm">{currentValue(data, key) || "Vacío"}</Text>
													</div>
													<div>
														<Text className="text-xs font-semibold uppercase text-zinc-500">Sugerencia</Text>
														<Text className="mt-1 whitespace-pre-line text-sm">{suggestion[key] || "Sin sugerencia"}</Text>
													</div>
													<Button type="button" outline onClick={() => applyOne(key)}>Aplicar</Button>
												</div>
											))}
										</div>
									</>
								) : (
									<div className="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
										<Text className="font-medium">Sin sugerencias todavía</Text>
										<Text className="mt-1 text-sm text-zinc-500">Describe la campaña para generar alternativas.</Text>
									</div>
								)}
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
}
