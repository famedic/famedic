import { useEffect, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";

function getCsrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function prettyJson(value) {
	if (value == null) return "";
	try {
		return JSON.stringify(value, null, 2);
	} catch {
		return String(value);
	}
}

function copyTextToClipboard(text) {
	if (!text) return;
	if (navigator?.clipboard?.writeText) {
		navigator.clipboard.writeText(text).catch(() => {});
		return;
	}
	const el = document.createElement("textarea");
	el.value = text;
	document.body.appendChild(el);
	el.select();
	document.execCommand("copy");
	el.remove();
}

function JsonBlock({ value, emptyMessage = "Sin datos.", className = "" }) {
	const text = prettyJson(value);

	if (!text) {
		return <p className="text-sm text-zinc-500">{emptyMessage}</p>;
	}

	return (
		<pre
			className={`max-h-96 overflow-auto rounded-lg bg-zinc-950 p-3 text-[11px] leading-relaxed text-zinc-100 ${className}`}
		>
			{text}
		</pre>
	);
}

function PostmanTab({ active, onClick, label }) {
	return (
		<button
			type="button"
			onClick={onClick}
			className={`border-b-2 px-3 py-2 text-xs font-medium transition ${
				active
					? "border-orange-500 text-orange-600 dark:text-orange-400"
					: "border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
			}`}
		>
			{label}
		</button>
	);
}

function MetaRow({ label, value, mono = false }) {
	return (
		<div className="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
			<div className="text-[10px] uppercase tracking-wide text-zinc-400">
				{label}
			</div>
			<div
				className={`mt-0.5 break-all text-zinc-800 dark:text-zinc-100 ${
					mono ? "font-mono text-xs" : "text-sm"
				}`}
			>
				{value}
			</div>
		</div>
	);
}

export default function LaboratoryGdaConsultConsole({ detail }) {
	const gdaTest = detail.gdaTest;
	const consultUrl =
		gdaTest?.consult_url ||
		gdaTest?.consult_preview?.url ||
		"GDA_RESULTS_CONSULT_URL";

	const [orderId, setOrderId] = useState(
		gdaTest?.order_id || detail.gdaOrderId || "",
	);
	const [payloadText, setPayloadText] = useState(
		prettyJson(gdaTest?.payload) || "",
	);
	const [requestTab, setRequestTab] = useState("body");
	const [responseTab, setResponseTab] = useState("body");
	const [jsonError, setJsonError] = useState(null);
	const [testing, setTesting] = useState(false);
	const [result, setResult] = useState(null);
	const [copied, setCopied] = useState(null);

	useEffect(() => {
		setOrderId(gdaTest?.order_id || detail.gdaOrderId || "");
		setPayloadText(prettyJson(gdaTest?.payload) || "");
		setJsonError(null);
		setResult(null);
		setRequestTab("body");
		setResponseTab("body");
	}, [detail.orderKey, gdaTest?.notification_id, gdaTest?.order_id]);

	const markCopied = (key) => {
		setCopied(key);
		window.setTimeout(() => setCopied(null), 1500);
	};

	const resetPayload = () => {
		setPayloadText(prettyJson(gdaTest?.payload) || "");
		setJsonError(null);
		setRequestTab("body");
	};

	const loadPreparedPayload = () => {
		if (gdaTest?.consult_preview?.payload) {
			setPayloadText(prettyJson(gdaTest.consult_preview.payload));
			setJsonError(null);
			setRequestTab("body");
			return;
		}
		setJsonError(
			gdaTest?.consult_preview_error ||
				"No hay payload preparado de consulta disponible.",
		);
	};

	const formatPayload = () => {
		try {
			const parsed = JSON.parse(payloadText);
			setPayloadText(prettyJson(parsed));
			setJsonError(null);
		} catch (e) {
			setJsonError(
				"JSON inválido: " + (e instanceof Error ? e.message : String(e)),
			);
		}
	};

	const runTest = async () => {
		setTesting(true);
		setResult(null);
		setJsonError(null);
		setResponseTab("body");

		let parsedPayload;
		try {
			parsedPayload = JSON.parse(payloadText);
		} catch (e) {
			setJsonError(
				"JSON inválido: " + (e instanceof Error ? e.message : String(e)),
			);
			setTesting(false);
			return;
		}

		try {
			const response = await fetch(
				route("admin.laboratory-notifications-monitor.test-gda-consult", {
					orderKey: detail.orderKey,
				}),
				{
					method: "POST",
					headers: {
						Accept: "application/json",
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
						"X-CSRF-TOKEN": getCsrfToken(),
					},
					credentials: "same-origin",
					body: JSON.stringify({
						notification_id: gdaTest?.notification_id ?? null,
						order_id: orderId || null,
						payload: parsedPayload,
					}),
				},
			);

			const json = await response.json();
			setResult(json);
		} catch (e) {
			setResult({
				success: false,
				message:
					e instanceof Error
						? e.message
						: "Error de red al consultar GDA.",
				error:
					e instanceof Error
						? e.message
						: "Error de red al consultar GDA.",
			});
		} finally {
			setTesting(false);
		}
	};

	const displayedUrl = result?.url || consultUrl;
	const httpStatus = result?.http_status;
	const statusTone =
		httpStatus == null
			? "slate"
			: httpStatus >= 200 && httpStatus < 300
				? "emerald"
				: httpStatus >= 400
					? "red"
					: "amber";

	return (
		<div className="overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/40">
			{/* Barra tipo Postman: método + URL + Send */}
			<div className="border-b border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
				<div className="flex flex-col gap-2 sm:flex-row sm:items-stretch">
					<div className="flex min-w-0 flex-1 overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600">
						<div className="flex items-center border-r border-zinc-300 bg-orange-50 px-3 text-sm font-semibold text-orange-700 dark:border-zinc-600 dark:bg-orange-950/40 dark:text-orange-300">
							POST
						</div>
						<input
							type="text"
							readOnly
							value={displayedUrl}
							title={displayedUrl}
							className="min-w-0 flex-1 bg-white px-3 py-2 font-mono text-xs text-zinc-700 outline-none dark:bg-zinc-950 dark:text-zinc-200"
						/>
					</div>
					<button
						type="button"
						onClick={runTest}
						disabled={testing || !payloadText}
						className="inline-flex items-center justify-center rounded-lg bg-orange-500 px-5 py-2 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50"
					>
						{testing ? "Sending…" : "Send"}
					</button>
				</div>

				<div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
					<div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
						<span className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
							Order ID
						</span>
						<input
							type="text"
							value={orderId}
							onChange={(e) => setOrderId(e.target.value)}
							className="min-w-[12rem] flex-1 rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950"
						/>
						{gdaTest?.notification_id && (
							<Badge color="slate">notif #{gdaTest.notification_id}</Badge>
						)}
						{gdaTest?.consult_preview?.resolved_source && (
							<Badge color="sky">
								fuente: {gdaTest.consult_preview.resolved_source}
							</Badge>
						)}
					</div>
					<div className="flex flex-wrap gap-1.5">
						<button
							type="button"
							onClick={resetPayload}
							className="rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
						>
							Reset webhook
						</button>
						<button
							type="button"
							onClick={loadPreparedPayload}
							className="rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
						>
							Cargar preparado
						</button>
						<button
							type="button"
							onClick={formatPayload}
							className="rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
						>
							Beautify
						</button>
					</div>
				</div>
			</div>

			{gdaTest?.consult_preview_error && !gdaTest?.payload && (
				<div className="border-b border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
					{gdaTest.consult_preview_error}
				</div>
			)}

			{/* Request */}
			<div className="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
				<div className="flex items-center justify-between gap-2 border-b border-zinc-200 px-3 dark:border-zinc-700">
					<div className="flex gap-1">
						<PostmanTab
							active={requestTab === "body"}
							onClick={() => setRequestTab("body")}
							label="Body"
						/>
						<PostmanTab
							active={requestTab === "preview"}
							onClick={() => setRequestTab("preview")}
							label="Prepared"
						/>
						<PostmanTab
							active={requestTab === "info"}
							onClick={() => setRequestTab("info")}
							label="Info"
						/>
					</div>
					<button
						type="button"
						onClick={() => {
							copyTextToClipboard(payloadText);
							markCopied("request");
						}}
						className="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
					>
						{copied === "request" ? "Copiado" : "Copy"}
					</button>
				</div>

				<div className="p-3">
					{requestTab === "body" && (
						<div className="space-y-2">
							<div className="flex items-center gap-2 text-[11px] text-zinc-400">
								<span className="rounded bg-zinc-100 px-1.5 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
									raw
								</span>
								<span>JSON</span>
								<span className="text-zinc-300 dark:text-zinc-600">·</span>
								<span>token inyectado en servidor</span>
							</div>
							<textarea
								value={payloadText}
								onChange={(e) => setPayloadText(e.target.value)}
								spellCheck={false}
								rows={14}
								className="w-full rounded-lg border border-zinc-300 bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-100 outline-none ring-orange-500/30 focus:ring-2 dark:border-zinc-700"
							/>
							{jsonError && (
								<p className="text-sm text-red-600 dark:text-red-400">
									{jsonError}
								</p>
							)}
						</div>
					)}

					{requestTab === "preview" && (
						<div className="space-y-2">
							{gdaTest?.consult_preview ? (
								<>
									<div className="flex flex-wrap gap-1.5">
										{gdaTest.consult_preview.resolved_id && (
											<Badge color="sky">
												ID: {gdaTest.consult_preview.resolved_id}
											</Badge>
										)}
										{gdaTest.consult_preview.resolved_source && (
											<Badge color="slate">
												fuente: {gdaTest.consult_preview.resolved_source}
											</Badge>
										)}
									</div>
									<JsonBlock value={gdaTest.consult_preview.payload} />
								</>
							) : (
								<p className="text-sm text-zinc-500">
									{gdaTest?.consult_preview_error ||
										"Sin payload preparado."}
								</p>
							)}
						</div>
					)}

					{requestTab === "info" && (
						<div className="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
							<p>
								Esta consola llama a GDA con el mismo flujo de producción
								(resolución de ID consultable + token de marca).
							</p>
							<p>
								El PDF base64 se muestra en el tab Response → base_64 para
								no saturar el Body.
							</p>
							<p className="break-all font-mono text-xs text-zinc-500">
								Endpoint: {consultUrl}
							</p>
						</div>
					)}
				</div>
			</div>

			{/* Response */}
			<div className="bg-white dark:bg-zinc-900">
				<div className="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
					<div className="flex flex-wrap items-center gap-2">
						<span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
							Response
						</span>
						{result ? (
							<>
								{httpStatus != null && (
									<Badge color={statusTone}>
										{httpStatus}{" "}
										{result.success
											? "OK"
											: result.gda_not_available
												? "No available"
												: "Error"}
									</Badge>
								)}
								{result.has_pdf && (
									<Badge color="emerald">PDF presente</Badge>
								)}
								{result.resolved_id && (
									<Badge color="sky">ID {result.resolved_id}</Badge>
								)}
							</>
						) : (
							<span className="text-xs text-zinc-400">
								{testing
									? "Esperando respuesta de GDA…"
									: "Pulsa Send para ejecutar la consulta"}
							</span>
						)}
					</div>
					{result && (
						<button
							type="button"
							onClick={() => {
								const text =
									responseTab === "request"
										? prettyJson(result.request_payload)
										: responseTab === "base64"
											? result.pdf_base64 || ""
											: prettyJson(result.response_payload);
								copyTextToClipboard(text);
								markCopied("response");
							}}
							className="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
						>
							{copied === "response" ? "Copiado" : "Copy"}
						</button>
					)}
				</div>

				{result && (
					<div className="flex gap-1 border-b border-zinc-200 px-3 dark:border-zinc-700">
						<PostmanTab
							active={responseTab === "body"}
							onClick={() => setResponseTab("body")}
							label="Body"
						/>
						<PostmanTab
							active={responseTab === "request"}
							onClick={() => setResponseTab("request")}
							label="Request sent"
						/>
						<PostmanTab
							active={responseTab === "headers"}
							onClick={() => setResponseTab("headers")}
							label="Meta"
						/>
						<PostmanTab
							active={responseTab === "base64"}
							onClick={() => setResponseTab("base64")}
							label="base_64"
						/>
					</div>
				)}

				<div className="p-3">
					{!result && !testing && (
						<div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-zinc-300 bg-zinc-50 text-sm text-zinc-400 dark:border-zinc-700 dark:bg-zinc-950/50">
							La respuesta aparecerá aquí
						</div>
					)}

					{testing && (
						<div className="flex h-40 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-950/50">
							Consultando GDA…
						</div>
					)}

					{result && responseTab === "body" && (
						<div className="space-y-2">
							{(result.message || result.error) && (
								<p
									className={`text-sm ${
										result.success
											? "text-zinc-600 dark:text-zinc-300"
											: "text-red-600 dark:text-red-400"
									}`}
								>
									{result.message || result.error}
								</p>
							)}
							<JsonBlock
								value={result.response_payload}
								emptyMessage="Sin response body."
							/>
						</div>
					)}

					{result && responseTab === "request" && (
						<JsonBlock
							value={result.request_payload}
							emptyMessage="Sin request."
						/>
					)}

					{result && responseTab === "headers" && (
						<div className="grid gap-2 text-sm sm:grid-cols-2">
							<MetaRow label="URL" value={result.url || "—"} mono />
							<MetaRow
								label="HTTP status"
								value={
									result.http_status != null
										? String(result.http_status)
										: "—"
								}
							/>
							<MetaRow
								label="Resolved ID"
								value={result.resolved_id || "—"}
								mono
							/>
							<MetaRow
								label="Fuente ID"
								value={result.resolved_source || "—"}
							/>
							<MetaRow label="Brand key" value={result.brand_key || "—"} />
							<MetaRow
								label="PDF"
								value={
									result.has_pdf
										? `Presente (${(result.pdf_base64 || "").length.toLocaleString()} chars)`
										: "No"
								}
							/>
						</div>
					)}

					{result && responseTab === "base64" && (
						<div className="space-y-2">
							{result.pdf_base64 ? (
								<>
									<div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-zinc-400">
										<span>
											infogda_resultado_b64 ·{" "}
											{result.pdf_base64.length.toLocaleString()} chars
										</span>
										<button
											type="button"
											onClick={() => {
												copyTextToClipboard(result.pdf_base64);
												markCopied("base64");
											}}
											className="rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
										>
											{copied === "base64" ? "Copiado" : "Copiar base64"}
										</button>
									</div>
									<textarea
										readOnly
										value={result.pdf_base64}
										spellCheck={false}
										rows={12}
										className="w-full rounded-lg border border-zinc-300 bg-zinc-950 p-3 font-mono text-[10px] leading-relaxed text-zinc-100 outline-none dark:border-zinc-700"
									/>
								</>
							) : (
								<p className="text-sm text-zinc-500">
									Esta respuesta no incluye PDF en base64.
								</p>
							)}
						</div>
					)}
				</div>
			</div>
		</div>
	);
}
