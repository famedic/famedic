import { useEffect, useMemo, useRef, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Cog6ToothIcon,
	CodeBracketIcon,
	ArrowPathIcon,
} from "@heroicons/react/16/solid";
import DocumentPanel from "@/Components/Admin/ClinicalInterpreter/DocumentPanel";
import InterpretationPanel from "@/Components/Admin/ClinicalInterpreter/InterpretationPanel";
import MatchingPanel from "@/Components/Admin/ClinicalInterpreter/MatchingPanel";
import ResultPanel from "@/Components/Admin/ClinicalInterpreter/ResultPanel";
import AiConfigDrawer from "@/Components/Admin/ClinicalInterpreter/AiConfigDrawer";
import JsonOcrDrawer from "@/Components/Admin/ClinicalInterpreter/JsonOcrDrawer";
import ManualSearchDrawer from "@/Components/Admin/ClinicalInterpreter/ManualSearchDrawer";
import ValidationProgressBar from "@/Components/Admin/ClinicalInterpreter/ValidationProgressBar";
import HumanValidationCenter from "@/Components/Admin/ClinicalInterpreter/HumanValidationCenter";
import CommercialIntegrationPanel from "@/Components/Admin/ClinicalInterpreter/CommercialIntegrationPanel";
import ClinicalOrderSummary from "@/Components/Admin/ClinicalInterpreter/ClinicalOrderSummary";
import { recomputeSummary } from "@/Components/Admin/ClinicalInterpreter/matchingHelpers";
import {
	buildValidationItems,
	pipelineStages,
	validationProgress,
} from "@/Components/Admin/ClinicalInterpreter/validationHelpers";

function getCsrf() {
	return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function cloneMatches(matches) {
	return {
		medications: (matches?.medications || []).map((m) => ({ ...m })),
		studies: (matches?.studies || []).map((m) => ({ ...m })),
	};
}

function updateMatchRow(matches, detectionId, updater) {
	const next = cloneMatches(matches);
	for (const key of ["medications", "studies"]) {
		next[key] = next[key].map((row) =>
			row.detection_id === detectionId ? updater(row) : row,
		);
	}
	return next;
}

function updateValidationItem(items, detectionId, updater) {
	return items.map((item) =>
		item.detection_id === detectionId ? updater(item) : item,
	);
}

async function recordLearningSuggestion(payload) {
	try {
		await fetch(route("admin.clinical-interpreter.learning-suggestions.store"), {
			method: "POST",
			headers: {
				Accept: "application/json",
				"Content-Type": "application/json",
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCsrf(),
			},
			credentials: "same-origin",
			body: JSON.stringify(payload),
		});
	} catch {
		// Learning must never break the validation UX
	}
}

export default function MatchingEngine({
	meta: initialMeta,
	document: initialDocument,
	interpretation: initialInterpretation,
	matches: initialMatches,
	summary: initialSummary,
	ai_config: initialAiConfig,
	future_actions: initialFutureActions,
	prompt_catalog: initialPromptCatalog = [],
	interpretation_metrics: initialMetrics = null,
	vision: initialVision = null,
}) {
	const [meta, setMeta] = useState(initialMeta);
	const [documentMeta, setDocumentMeta] = useState(initialDocument);
	const [interpretation, setInterpretation] = useState(initialInterpretation);
	const [summarySeed, setSummarySeed] = useState(initialSummary);
	const [aiConfig, setAiConfig] = useState(initialAiConfig);
	const [promptCatalog, setPromptCatalog] = useState(initialPromptCatalog);
	const [futureActions] = useState(initialFutureActions);
	const [metrics, setMetrics] = useState(initialMetrics);
	const [rawJson, setRawJson] = useState(initialVision?.raw_json ?? null);
	const [visionConfigured] = useState(Boolean(initialVision?.configured));

	const [phase, setPhase] = useState("ready");
	const [matches, setMatches] = useState(() => cloneMatches(initialMatches));
	const [validationItems, setValidationItems] = useState(() =>
		buildValidationItems(initialMatches),
	);
	const [aiOpen, setAiOpen] = useState(false);
	const [jsonOpen, setJsonOpen] = useState(false);
	const [searchOpen, setSearchOpen] = useState(false);
	const [searchTarget, setSearchTarget] = useState(null);
	const [interpreting, setInterpreting] = useState(false);
	const [interpretError, setInterpretError] = useState(null);
	const [previewUrl, setPreviewUrl] = useState(
		initialDocument?.preview_url || null,
	);
	const lastFileRef = useRef(null);
	const [commercialProposal, setCommercialProposal] = useState(null);
	const [commercialLoading, setCommercialLoading] = useState(false);
	const [commercialMessage, setCommercialMessage] = useState(null);
	const [commercialTone, setCommercialTone] = useState("success");
	const [clinicalOrder, setClinicalOrder] = useState(null);
	const previewObjectUrlRef = useRef(null);

	const summary = useMemo(
		() => (phase === "ready" ? recomputeSummary(matches) : summarySeed),
		[matches, phase, summarySeed],
	);

	const progress = useMemo(
		() => validationProgress(validationItems),
		[validationItems],
	);

	const stages = useMemo(() => {
		const base = pipelineStages({
			hasInterpretation: Boolean(
				interpretation?.ai_json || metrics || interpretation?.studies?.length,
			),
			hasMatching: Boolean((matches?.studies || []).length),
			validationPercent: progress.percent,
			validationComplete: progress.complete,
		});

		return [
			...base,
			{
				id: "commercial",
				label: "Commercial",
				done: Boolean(progress.complete && commercialProposal),
			},
			{
				id: "clinical_order",
				label: "Clinical Order",
				done: Boolean(clinicalOrder?.id),
			},
		];
	}, [interpretation, metrics, matches, progress, commercialProposal, clinicalOrder]);

	const commercialContextBody = () => {
		const doc = documentMeta ? { ...documentMeta } : null;
		if (doc) {
			delete doc.preview_url;
			delete doc.contents;
			delete doc.base64;
		}

		return {
			session_id: interpretation?.session_id || null,
			items: (validationItems || []).filter((i) => i.type === "laboratory"),
			document: doc,
			interpretation,
			metrics,
		};
	};

	useEffect(() => {
		if (!progress.complete) {
			setCommercialProposal(null);
			setCommercialMessage(null);
			setCommercialTone("success");
			setClinicalOrder(null);
			return undefined;
		}

		let cancelled = false;
		const loadProposal = async () => {
			setCommercialLoading(true);
			try {
				const res = await fetch(
					route("admin.clinical-interpreter.commercial.proposal"),
					{
						method: "POST",
						headers: {
							Accept: "application/json",
							"Content-Type": "application/json",
							"X-Requested-With": "XMLHttpRequest",
							"X-CSRF-TOKEN": getCsrf(),
						},
						credentials: "same-origin",
						body: JSON.stringify(commercialContextBody()),
					},
				);
				const data = await res.json().catch(() => ({}));
				if (cancelled) return;
				if (res.ok && data.ok) {
					setCommercialProposal(data.proposal);
					if (data.clinical_order) {
						setClinicalOrder(data.clinical_order);
					}
					setCommercialMessage(null);
				} else {
					setCommercialProposal(null);
					setCommercialTone("error");
					setCommercialMessage(
						data.message || "No se pudo cargar la propuesta comercial.",
					);
				}
			} catch {
				if (!cancelled) {
					setCommercialProposal(null);
					setCommercialTone("error");
					setCommercialMessage("Error de red al cargar la propuesta comercial.");
				}
			} finally {
				if (!cancelled) setCommercialLoading(false);
			}
		};

		loadProposal();
		return () => {
			cancelled = true;
		};
	}, [progress.complete, validationItems, interpretation, documentMeta, metrics]);

	useEffect(() => {
		return () => {
			if (previewObjectUrlRef.current) {
				URL.revokeObjectURL(previewObjectUrlRef.current);
				previewObjectUrlRef.current = null;
			}
		};
	}, []);

	const setLocalPreview = (file) => {
		if (previewObjectUrlRef.current) {
			URL.revokeObjectURL(previewObjectUrlRef.current);
			previewObjectUrlRef.current = null;
		}
		const localPreview = URL.createObjectURL(file);
		previewObjectUrlRef.current = localPreview;
		setPreviewUrl(localPreview);
	};

	const applyPayload = (payload) => {
		const nextMatches = cloneMatches(payload.matches);
		setMeta(payload.meta || meta);
		setDocumentMeta(payload.document);
		setInterpretation(payload.interpretation);
		setMatches(nextMatches);
		setValidationItems(buildValidationItems(nextMatches));
		setSummarySeed(payload.summary);
		setAiConfig(payload.ai_config);
		setPromptCatalog(payload.prompt_catalog || []);
		setMetrics(payload.interpretation_metrics || null);
		setRawJson(payload.vision?.raw_json || payload.interpretation?.ai_json);
		setPreviewUrl(payload.document?.preview_url || previewUrl);
		setCommercialProposal(null);
		setCommercialMessage(null);
		setCommercialTone("success");
		setClinicalOrder(null);
		setPhase("ready");
		setJsonOpen(true);
	};

	const interpretFile = async (file) => {
		lastFileRef.current = file;
		setInterpretError(null);
		setInterpreting(true);
		setPhase("searching");
		setLocalPreview(file);

		try {
			const form = new FormData();
			form.append("document", file);

			const res = await fetch(route("admin.clinical-interpreter.interpret"), {
				method: "POST",
				headers: {
					Accept: "application/json",
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": getCsrf(),
				},
				credentials: "same-origin",
				body: form,
			});

			const data = await res.json().catch(() => ({}));

			if (!res.ok || !data.ok) {
				const message =
					res.status === 429
						? "Demasiadas interpretaciones. Espera un momento e intenta de nuevo."
						: data.message ||
							(data.error_type === "invalid_json"
								? "Error técnico: JSON inválido."
								: "No fue posible interpretar la receta.");
				setInterpretError(message);
				setPhase("ready");
				return;
			}

			applyPayload(data);
		} catch {
			setInterpretError("No fue posible interpretar la receta. Intenta de nuevo.");
			setPhase("ready");
		} finally {
			setInterpreting(false);
		}
	};

	const syncMatchFromValidation = (item, patch) => {
		setMatches((prev) =>
			updateMatchRow(prev, item.detection_id, (row) => ({
				...row,
				...patch,
			})),
		);
	};

	const confirmItem = async (item) => {
		const chosen =
			(item.alternatives || []).find(
				(a) => a.catalog_id === item.selected_catalog_id,
			) || item.match;
		if (!chosen) return;

		const wasCorrection =
			item.initial_catalog_id &&
			chosen.catalog_id &&
			item.initial_catalog_id !== chosen.catalog_id;

		const status = wasCorrection ? "corrected" : "confirmed";

		setValidationItems((prev) =>
			updateValidationItem(prev, item.detection_id, (row) => ({
				...row,
				match: chosen,
				selected_catalog_id: chosen.catalog_id,
				validation_status: status,
			})),
		);

		syncMatchFromValidation(item, {
			match: chosen,
			selected_catalog_id: chosen.catalog_id,
			user_decision: wasCorrection ? "manual" : "accepted",
			ui_state: wasCorrection ? "manual" : "accepted",
			engine_status: "exact",
			perhaps: null,
		});

		if (wasCorrection) {
			await recordLearningSuggestion({
				type: item.type === "medication" ? "medication" : "laboratory",
				detected_text: item.detected_name,
				confirmed_text: chosen.name,
				confirmed_catalog_id: chosen.catalog_id,
				action: "corrected",
				session_id: interpretation?.session_id || null,
				meta: {
					initial_match_name: item.initial_match_name,
					similarity: chosen.similarity,
				},
			});
		}
	};

	const ignoreItem = async (item) => {
		setValidationItems((prev) =>
			updateValidationItem(prev, item.detection_id, (row) => ({
				...row,
				validation_status: "ignored",
				selected_catalog_id: null,
			})),
		);
		syncMatchFromValidation(item, {
			user_decision: "ignored",
			ui_state: "ignored",
			selected_catalog_id: null,
		});

		await recordLearningSuggestion({
			type: item.type === "medication" ? "medication" : "laboratory",
			detected_text: item.detected_name,
			confirmed_text: "(ignored)",
			confirmed_catalog_id: null,
			action: "ignored",
			session_id: interpretation?.session_id || null,
			meta: null,
		});
	};

	const selectAlternative = (item, alt) => {
		setValidationItems((prev) =>
			updateValidationItem(prev, item.detection_id, (row) => ({
				...row,
				match: alt,
				selected_catalog_id: alt.catalog_id,
				validation_status: "pending",
			})),
		);
		syncMatchFromValidation(item, {
			match: alt,
			selected_catalog_id: alt.catalog_id,
			user_decision: null,
			engine_status: alt.similarity >= 92 ? "exact" : "partial",
			ui_state: alt.similarity >= 92 ? "match_found" : "needs_validation",
			perhaps: null,
		});
	};

	const openSearch = (item) => {
		setSearchTarget({
			detection_id: item.detection_id,
			detected_name: item.detected_name,
			type: item.type,
		});
		setSearchOpen(true);
	};

	const selectManual = async (target, catalogItem) => {
		const match = {
			catalog_id: catalogItem.id,
			name: catalogItem.name,
			sku: catalogItem.sku || catalogItem.code,
			code: catalogItem.code || catalogItem.sku,
			price: catalogItem.price,
			delivery_time: catalogItem.delivery_time,
			laboratory: catalogItem.laboratory || catalogItem.brand,
			available: catalogItem.available,
			brand: catalogItem.brand,
			similarity: catalogItem.similarity,
			reason: catalogItem.match_reason || catalogItem.reason,
			match_status: catalogItem.match_status,
		};

		const source = validationItems.find(
			(i) => i.detection_id === target.detection_id,
		);
		const wasCorrection =
			!source?.initial_catalog_id ||
			source.initial_catalog_id !== match.catalog_id;

		setValidationItems((prev) =>
			updateValidationItem(prev, target.detection_id, (row) => ({
				...row,
				match,
				selected_catalog_id: match.catalog_id,
				validation_status: wasCorrection ? "corrected" : "confirmed",
			})),
		);

		setMatches((prev) =>
			updateMatchRow(prev, target.detection_id, (r) => ({
				...r,
				user_decision: "manual",
				ui_state: "manual",
				engine_status: "exact",
				selected_catalog_id: match.catalog_id,
				match,
				perhaps: null,
			})),
		);

		if (wasCorrection) {
			await recordLearningSuggestion({
				type: target.type === "medication" ? "medication" : "laboratory",
				detected_text: target.detected_name,
				confirmed_text: match.name,
				confirmed_catalog_id: match.catalog_id,
				action: "corrected",
				session_id: interpretation?.session_id || null,
				meta: { source: "manual_search" },
			});
		}

		setSearchOpen(false);
		setSearchTarget(null);
	};

	const postCommercialAction = async (routeName) => {
		if (!progress.complete) return;
		setCommercialLoading(true);
		setCommercialMessage(null);
		try {
			const res = await fetch(route(routeName), {
				method: "POST",
				headers: {
					Accept: "application/json",
					"Content-Type": "application/json",
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": getCsrf(),
				},
				credentials: "same-origin",
				body: JSON.stringify(commercialContextBody()),
			});
			const data = await res.json().catch(() => ({}));
			if (res.ok && data.ok) {
				if (data.proposal) setCommercialProposal(data.proposal);
				if (data.clinical_order) setClinicalOrder(data.clinical_order);
				setCommercialTone("success");
				setCommercialMessage(
					data.message || "Acción comercial preparada correctamente.",
				);
			} else {
				setCommercialTone("error");
				setCommercialMessage(
					data.message ||
						data.errors?.items?.[0] ||
						"No se pudo preparar la acción comercial.",
				);
			}
		} catch {
			setCommercialTone("error");
			setCommercialMessage("Error de red al preparar la acción comercial.");
		} finally {
			setCommercialLoading(false);
		}
	};

	const saveClinicalOrder = () =>
		postCommercialAction("admin.clinical-interpreter.clinical-orders.store");

	const openClinicalOrder = () => {
		if (!clinicalOrder?.uuid) return;
		window.location.href = route(
			"admin.clinical-interpreter.clinical-orders.show",
			clinicalOrder.uuid,
		);
	};

	const handleResultAction = (actionId) => {
		if (actionId === "create_quote") {
			postCommercialAction("admin.clinical-interpreter.commercial.quote");
			return;
		}
		if (actionId === "add_to_cart") {
			postCommercialAction("admin.clinical-interpreter.commercial.cart");
			return;
		}
		if (actionId === "save_interpretation") {
			saveClinicalOrder();
		}
	};

	const reRunInterpretation = () => {
		if (!lastFileRef.current) {
			setInterpretError("Sube una receta primero para reejecutar la interpretación.");
			return;
		}
		interpretFile(lastFileRef.current);
	};

	return (
		<AdminLayout title="Clinical Matching Engine">
			<div className="space-y-5 pb-8">
				<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
					<div className="space-y-2">
						<nav className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]">
							<a
								href={route("admin.clinical-interpreter.index")}
								className="font-medium text-zinc-400 hover:text-famedic-light"
							>
								AI Clinical Interpreter
							</a>
							<span className="text-zinc-300">/</span>
							<span className="font-semibold text-zinc-700 dark:text-zinc-200">
								Nueva interpretación
							</span>
						</nav>
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Clinical Matching Workspace</Heading>
							<Badge color="famedic">v1.0 Laboratorio</Badge>
							<Badge color="sky">Human Validation</Badge>
							{visionConfigured ? (
								<Badge color="emerald">API configurada</Badge>
							) : (
								<Badge color="amber">Falta OPENAI_API_KEY</Badge>
							)}
						</div>
						<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
							OpenAI interpreta la orden de laboratorio. Famedic hace matching
							de estudios. El operador confirma.
						</Text>
						{meta?.note && (
							<p className="text-[11px] text-zinc-400">{meta.note}</p>
						)}
					</div>

					<div className="flex flex-wrap gap-2">
						<Button outline onClick={() => setAiOpen(true)}>
							<Cog6ToothIcon data-slot="icon" />
							Configuración IA
						</Button>
						<Button outline onClick={() => setJsonOpen(true)}>
							<CodeBracketIcon data-slot="icon" />
							OCR ↔ JSON
						</Button>
						<Button
							outline
							onClick={reRunInterpretation}
							disabled={interpreting || !lastFileRef.current}
							title={
								lastFileRef.current
									? "Vuelve a interpretar la última imagen con Vision + Matching"
									: "Sube una receta primero"
							}
						>
							<ArrowPathIcon data-slot="icon" />
							Reejecutar interpretación
						</Button>
					</div>
				</div>

				<ValidationProgressBar stages={stages} percent={progress.percent} />

				<div className="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-xs text-zinc-500 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<span className="font-semibold uppercase tracking-wide text-zinc-400">
						Flujo
					</span>
					<span>Documento</span>
					<span>→</span>
					<span>Vision</span>
					<span>→</span>
					<span>Matching</span>
					<span>→</span>
					<span className="font-semibold text-famedic-dark dark:text-famedic-light">
						Human Validation
					</span>
					<span>→</span>
					<span>Commercial</span>
					<span>→</span>
					<span
						className={
							clinicalOrder?.id
								? "font-semibold text-famedic-dark dark:text-famedic-light"
								: ""
						}
					>
						Clinical Order
					</span>
					<span>→</span>
					<span>Carrito / Checkout</span>
				</div>

				<div className="grid grid-cols-1 gap-4 xl:grid-cols-12 xl:gap-3">
					<div className="xl:col-span-3">
						<DocumentPanel
							document={documentMeta}
							previewUrl={previewUrl}
							interpreting={interpreting}
							error={interpretError}
							onFileSelected={interpretFile}
							onRetry={() =>
								lastFileRef.current && interpretFile(lastFileRef.current)
							}
						/>
					</div>
					<div className="xl:col-span-3">
						<InterpretationPanel interpretation={interpretation} />
					</div>
					<div className="xl:col-span-3">
						<MatchingPanel
							matches={matches}
							phase={
								interpreting
									? "searching"
									: phase === "ready"
										? "ready"
										: phase
							}
							showActions={false}
							onAccept={() => {}}
							onChange={() => {}}
							onSearch={() => {}}
							onIgnore={() => {}}
							onSelectAlternative={() => {}}
						/>
					</div>
					<div className="xl:col-span-3">
						<ResultPanel
							summary={summary}
							futureActions={futureActions}
							phase={
								interpreting
									? "searching"
									: phase === "ready"
										? "ready"
										: phase
							}
							validationComplete={progress.complete}
							validationPending={progress.pending}
							onAction={handleResultAction}
						/>
					</div>
				</div>

				<HumanValidationCenter
					items={validationItems}
					onConfirm={confirmItem}
					onSelectAlternative={selectAlternative}
					onSearch={openSearch}
					onIgnore={ignoreItem}
				/>

				<CommercialIntegrationPanel
					enabled={progress.complete}
					proposal={commercialProposal}
					loading={commercialLoading}
					actionMessage={commercialMessage}
					actionTone={commercialTone}
					onCreateQuote={() =>
						postCommercialAction("admin.clinical-interpreter.commercial.quote")
					}
					onAddToCart={() =>
						postCommercialAction("admin.clinical-interpreter.commercial.cart")
					}
					onSaveDraft={() =>
						postCommercialAction("admin.clinical-interpreter.commercial.draft")
					}
				/>

				<ClinicalOrderSummary
					order={clinicalOrder}
					enabled={progress.complete}
					busy={commercialLoading}
					onSave={saveClinicalOrder}
					onOpen={openClinicalOrder}
					onCreateQuote={() =>
						postCommercialAction("admin.clinical-interpreter.commercial.quote")
					}
					onCreateCart={() =>
						postCommercialAction("admin.clinical-interpreter.commercial.cart")
					}
				/>
			</div>

			<AiConfigDrawer
				open={aiOpen}
				onClose={() => setAiOpen(false)}
				config={aiConfig}
				promptCatalog={promptCatalog}
			/>
			<JsonOcrDrawer
				open={jsonOpen}
				onClose={() => setJsonOpen(false)}
				interpretation={interpretation}
				metrics={metrics}
				rawJson={rawJson}
			/>
			<ManualSearchDrawer
				open={searchOpen}
				onClose={() => {
					setSearchOpen(false);
					setSearchTarget(null);
				}}
				target={searchTarget}
				onSelect={selectManual}
			/>
		</AdminLayout>
	);
}
