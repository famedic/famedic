import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";

const OBJECTIVES = ["preventiva", "temporada", "perfil", "promocion"];

function csrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

function formatPrice(cents) {
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(Number(cents || 0) / 100);
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
		collection_name: data?.collection_name || "",
		public_title: data?.public_title || "",
		public_description: data?.public_description || "",
		reasoning_summary: data?.reasoning_summary || "",
		items: Array.isArray(data?.items) ? data.items : [],
	};
}

export default function MarketingCampaignAiCollectionAssistant({
	brand,
	selectedItems,
	onApply,
}) {
	const [open, setOpen] = useState(false);
	const [context, setContext] = useState("");
	const [desiredCount, setDesiredCount] = useState(6);
	const [objective, setObjective] = useState("preventiva");
	const [status, setStatus] = useState("idle");
	const [error, setError] = useState("");
	const [suggestion, setSuggestion] = useState(null);

	const candidates = selectedItems.slice(0, 40);
	const disabled =
		!brand ||
		candidates.length === 0 ||
		context.trim().length < 20 ||
		status === "enviando" ||
		status === "generando";

	const generate = async () => {
		if (disabled) return;
		setError("");
		setStatus("enviando");

		try {
			const response = await fetch(route("admin.marketing-campaigns.ai.collection"), {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrfToken(),
				},
				body: JSON.stringify({
					brand,
					context,
					desired_count: desiredCount,
					objective,
					candidate_ids: candidates.map((item) => item.id),
				}),
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

	const applySuggestion = () => {
		if (!suggestion) return;

		onApply({
			name: suggestion.collection_name,
			public_title: suggestion.public_title,
			public_description: suggestion.public_description,
			items: suggestion.items.map((item) => item.study).filter(Boolean),
		});
	};

	return (
		<Card className="space-y-4 border-lime-200 bg-lime-50 p-4 dark:border-lime-900 dark:bg-lime-950/30">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div>
					<Text className="font-semibold">Sugerir colección con IA</Text>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
						La IA elige únicamente entre los estudios que ya agregaste como candidatos.
					</Text>
				</div>
				<Button type="button" outline onClick={() => setOpen(true)}>
					Sugerir con IA
				</Button>
			</div>

			{!brand && (
				<Text className="text-sm text-amber-700 dark:text-amber-300">
					Selecciona una marca antes de usar el asistente.
				</Text>
			)}
			{brand && candidates.length === 0 && (
				<Text className="text-sm text-amber-700 dark:text-amber-300">
					Agrega estudios candidatos para que la IA pueda seleccionar.
				</Text>
			)}

			{open && (
				<div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/70 p-4">
					<div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl dark:bg-zinc-950">
						<div className="flex flex-wrap items-center justify-between gap-3">
							<Text className="text-lg font-semibold">Asistente de colección</Text>
							<Button type="button" outline onClick={() => setOpen(false)}>
								Cerrar
							</Button>
						</div>

						<div className="mt-5 grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]">
							<div className="space-y-4">
								<Field>
									<Label>Objetivo de la colección</Label>
									<Textarea
										rows={6}
										value={context}
										onChange={(event) => setContext(event.target.value)}
										placeholder="Ej. campaña preventiva para salud cerebral..."
									/>
									{context && context.trim().length < 20 && (
										<ErrorMessage>Agrega más contexto.</ErrorMessage>
									)}
								</Field>

								<Field>
									<Label>Cantidad sugerida</Label>
									<Input
										type="number"
										min="3"
										max="10"
										value={desiredCount}
										onChange={(event) => setDesiredCount(event.target.value)}
									/>
								</Field>

								<Field>
									<Label>Enfoque</Label>
									<div className="mt-2 flex flex-wrap gap-2">
										{OBJECTIVES.map((item) => (
											<Button key={item} type="button" outline={objective !== item} onClick={() => setObjective(item)}>
												{item}
											</Button>
										))}
									</div>
								</Field>

								<Button type="button" color="lime" disabled={disabled} onClick={generate}>
									{status === "enviando" || status === "generando"
										? "Generando propuesta..."
										: "Generar propuesta"}
								</Button>
								<Text className="text-xs text-zinc-500">
									No se envían datos personales. La propuesta debe revisarse antes de guardarse.
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
											<Button type="button" outline onClick={generate}>
												Regenerar
											</Button>
											<Button type="button" color="lime" onClick={applySuggestion}>
												Aplicar propuesta
											</Button>
										</div>

										<Card className="space-y-2 p-4">
											<Text className="font-semibold">{suggestion.public_title}</Text>
											<Text className="text-sm text-zinc-600 dark:text-zinc-300">
												{suggestion.public_description}
											</Text>
											<Text className="text-xs text-zinc-500">
												{suggestion.reasoning_summary}
											</Text>
										</Card>

										<div className="space-y-3">
											{suggestion.items.map((item) => (
												<Card key={item.laboratory_test_id} className="p-3">
													<Text className="font-medium">{item.study?.name}</Text>
													<Text className="mt-1 text-sm text-zinc-500">
														{item.study?.category_name || "Sin categoría"} · {formatPrice(item.study?.famedic_price_cents)}
													</Text>
													<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
														{item.reason}
													</Text>
												</Card>
											))}
										</div>
									</>
								) : (
									<div className="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
										<Text className="font-medium">Sin propuesta todavía</Text>
										<Text className="mt-1 text-sm text-zinc-500">
											Usa estudios candidatos reales para recibir una selección revisable.
										</Text>
									</div>
								)}
							</div>
						</div>
					</div>
				</div>
			)}
		</Card>
	);
}
