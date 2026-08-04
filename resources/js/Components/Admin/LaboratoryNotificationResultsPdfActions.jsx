import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";

function getCsrfToken() {
	return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function openPdfFromBase64(base64) {
	const pdfWindow = window.open("");
	if (!pdfWindow) {
		alert("Permite ventanas emergentes para ver el PDF.");
		return;
	}

	pdfWindow.document.write(
		`<iframe width="100%" height="100%" src="data:application/pdf;base64,${base64}"></iframe>`,
	);
}

export function pdfLocationBadge(pdf) {
	if (!pdf) return { color: "slate", label: "—" };
	switch (pdf.location) {
		case "storage":
			return { color: "emerald", label: pdf.label };
		case "db_base64":
			return { color: "famedic-lime", label: pdf.label };
		case "db_base64_stale":
			return { color: "amber", label: pdf.label };
		case "gda_provider":
			return { color: "sky", label: pdf.label };
		default:
			return { color: "slate", label: pdf.label };
	}
}

function formatDateTime(value) {
	if (!value) return "—";
	return new Date(value).toLocaleString("es-MX");
}

function maskStoragePath(path) {
	if (!path) return "—";
	if (path.length <= 30) return path;
	return path.substring(0, 12) + "…" + path.substring(path.length - 18);
}

async function postJson(routeName, orderKey) {
	const response = await fetch(route(routeName, { orderKey }), {
		method: "POST",
		headers: {
			Accept: "application/json",
			"Content-Type": "application/json",
			"X-Requested-With": "XMLHttpRequest",
			"X-CSRF-TOKEN": getCsrfToken(),
		},
		credentials: "same-origin",
	});

	const json = await response.json();

	if (!response.ok || !json.success) {
		const err = new Error(json.message || "La operación no se completó.");
		err.gdaNotAvailable = json.gda_not_available || false;
		err.resultsPdf = json.results_pdf || null;
		err.lastAttemptAt = json.last_attempt_at || null;
		throw err;
	}

	return json;
}

export default function LaboratoryNotificationResultsPdfActions({
	orderKey,
	resultsPdf,
	onResultsPdfUpdated,
}) {
	const [fetching, setFetching] = useState(false);
	const [forcing, setForcing] = useState(false);
	const [downloading, setDownloading] = useState(false);
	const [message, setMessage] = useState(null);
	const [warning, setWarning] = useState(null);
	const [error, setError] = useState(null);

	if (!resultsPdf || resultsPdf.location === "none") {
		return (
			<Text className="text-sm text-zinc-500">
				No hay notificaciones de resultados con PDF disponible para esta orden.
			</Text>
		);
	}

	const pdfBadge = pdfLocationBadge(resultsPdf);
	const canFetchFromGda = Boolean(resultsPdf.can_fetch_from_gda);
	const canForceRefresh = Boolean(resultsPdf.can_force_refresh_from_gda);
	const canDownload = Boolean(resultsPdf.can_download);

	const handleSuccess = (json) => {
		setMessage(json.message);
		setWarning(null);
		onResultsPdfUpdated?.(json.results_pdf);
		if (json.pdf_base64) {
			openPdfFromBase64(json.pdf_base64);
		}
	};

	const handleError = (err) => {
		if (err.gdaNotAvailable) {
			setWarning(err.message);
			setError(null);
			if (err.resultsPdf) {
				onResultsPdfUpdated?.(err.resultsPdf);
			}
		} else {
			setError(err instanceof Error ? err.message : "No se pudo completar la operación.");
			setWarning(null);
		}
	};

	const fetchFromGda = async () => {
		setFetching(true);
		setMessage(null);
		setError(null);
		setWarning(null);

		try {
			handleSuccess(
				await postJson("admin.laboratory-notifications-monitor.fetch-results", orderKey),
			);
		} catch (err) {
			handleError(err);
		} finally {
			setFetching(false);
		}
	};

	const forceRefreshFromGda = async () => {
		const confirmMsg = resultsPdf.is_manual_result
			? "Este resultado fue subido manualmente. ¿Está seguro de que desea sobrescribirlo con el resultado de GDA?"
			: "¿Forzar actualización desde GDA? Se consultará el resultado más reciente al laboratorio y se guardará en storage/S3.";

		if (!window.confirm(confirmMsg)) {
			return;
		}

		setForcing(true);
		setMessage(null);
		setError(null);
		setWarning(null);

		try {
			handleSuccess(
				await postJson(
					"admin.laboratory-notifications-monitor.force-refresh-results",
					orderKey,
				),
			);
		} catch (err) {
			handleError(err);
		} finally {
			setForcing(false);
		}
	};

	const downloadPdf = async () => {
		setDownloading(true);
		setMessage(null);
		setError(null);

		try {
			const response = await fetch(
				route("admin.laboratory-notifications-monitor.download-results", {
					orderKey,
				}),
				{
					headers: {
						Accept: "application/pdf",
						"X-Requested-With": "XMLHttpRequest",
					},
					credentials: "same-origin",
				},
			);

			if (!response.ok) {
				throw new Error("No se pudo descargar el PDF.");
			}

			const blob = await response.blob();
			const url = window.URL.createObjectURL(blob);
			const link = document.createElement("a");
			const disposition = response.headers.get("Content-Disposition") ?? "";
			const filenameMatch = disposition.match(/filename="([^"]+)"/);
			link.href = url;
			link.download = filenameMatch?.[1] ?? `resultados_${orderKey}.pdf`;
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);

			setMessage("Descarga del PDF completada.");
		} catch (err) {
			setError(
				err instanceof Error
					? err.message
					: "No se pudo descargar el PDF.",
			);
		} finally {
			setDownloading(false);
		}
	};

	return (
		<div className="space-y-4">
			<div className="space-y-2">
				<Badge color={pdfBadge.color}>{pdfBadge.label}</Badge>

				{resultsPdf.has_newer_results && (
					<div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
						<Text className="text-sm text-amber-900 dark:text-amber-100">
							<Strong>Hay resultados más recientes.</Strong> El PDF puede estar
							desactualizado respecto a la última notificación de GDA.
						</Text>
					</div>
				)}
			</div>

			<div className="grid gap-2 text-sm sm:grid-cols-2">
				<Text>
					Archivo en storage/S3:{" "}
					<Strong>{resultsPdf.has_pdf_in_storage ? "Sí" : "No"}</Strong>
				</Text>
				<Text>
					Ruta storage:{" "}
					<Strong>{maskStoragePath(resultsPdf.storage_path)}</Strong>
				</Text>
				<Text>
					Resultado manual:{" "}
					<Strong>{resultsPdf.is_manual_result ? "Sí" : "No"}</Strong>
				</Text>
				<Text>
					Resultado automático GDA:{" "}
					<Strong>{resultsPdf.is_gda_automatic ? "Sí" : "No"}</Strong>
				</Text>
				<Text>
					Base64 legacy en BD:{" "}
					<Strong>{resultsPdf.has_pdf_in_db ? "Sí" : "No"}</Strong>
				</Text>
				<Text>
					Disponible en GDA:{" "}
					<Strong>{resultsPdf.available_at_gda ? "Sí" : "No"}</Strong>
				</Text>
				<Text>
					ID consulta GDA:{" "}
					<Strong>{resultsPdf.gda_consult_id ?? "—"}</Strong>
				</Text>
				<Text>
					Fuente ID consulta:{" "}
					<Strong>{resultsPdf.gda_consult_id_source_label ?? "—"}</Strong>
				</Text>
				<Text>
					Última descarga vía API GDA:{" "}
					<Strong>{formatDateTime(resultsPdf.pdf_fetched_at)}</Strong>
				</Text>
				<Text>
					Última sincronización a storage:{" "}
					<Strong>{formatDateTime(resultsPdf.last_sync_at)}</Strong>
				</Text>
			</div>

		{resultsPdf.last_sync_error && (
			<div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900/50 dark:bg-red-950/30">
				<Text className="text-sm text-red-900 dark:text-red-100">
					<Strong>Último error de sync:</Strong>{" "}
					{resultsPdf.last_sync_error}
					{resultsPdf.last_sync_error_at && (
						<span className="ml-1 text-xs text-red-600 dark:text-red-400">
							({formatDateTime(resultsPdf.last_sync_error_at)})
						</span>
					)}
				</Text>
			</div>
		)}

		{resultsPdf.last_gda_not_available_at && !resultsPdf.has_pdf_in_storage && !resultsPdf.has_pdf_in_db && (
			<div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
				<Text className="text-sm text-amber-900 dark:text-amber-100">
					<Strong>Estado API GDA:</Strong> Resultado notificado, PDF aún no disponible
				</Text>
				<Text className="mt-1 text-xs text-amber-700 dark:text-amber-300">
					Último intento: {formatDateTime(resultsPdf.last_gda_not_available_at)}
				</Text>
				{resultsPdf.last_gda_not_available_message && (
					<Text className="text-xs text-amber-600 dark:text-amber-400">
						Mensaje GDA: {resultsPdf.last_gda_not_available_message}
					</Text>
				)}
			</div>
		)}

			<div className="flex flex-wrap gap-2">
				<Badge color={resultsPdf.has_pdf_in_storage ? "emerald" : "slate"}>
					En storage/S3: {resultsPdf.has_pdf_in_storage ? "Sí" : "No"}
				</Badge>
				{resultsPdf.is_manual_result && (
					<Badge color="violet">Resultado manual</Badge>
				)}
				{resultsPdf.is_gda_automatic && (
					<Badge color="emerald">GDA automático</Badge>
				)}
				{resultsPdf.has_pdf_in_db && (
					<Badge color="amber">Base64 legacy en BD</Badge>
				)}
				<Badge color={resultsPdf.available_at_gda ? "sky" : "slate"}>
					Disponible en GDA: {resultsPdf.available_at_gda ? "Sí" : "No"}
				</Badge>
				{resultsPdf.is_stale && (
					<Badge color="amber">Caché posiblemente desactualizada</Badge>
				)}
			</div>

			<div className="flex flex-wrap gap-2">
				{canDownload && (
					<Button color="emerald" onClick={downloadPdf} disabled={downloading}>
						{downloading ? "Descargando..." : "Descargar PDF"}
					</Button>
				)}
				{canFetchFromGda && (
					<Button color="sky" onClick={fetchFromGda} disabled={fetching || forcing}>
						{fetching ? "Sincronizando..." : "Sincronizar desde GDA a S3"}
					</Button>
				)}
				{canForceRefresh && (
					<Button color="amber" onClick={forceRefreshFromGda} disabled={fetching || forcing}>
						{forcing ? "Actualizando..." : "Forzar actualización desde GDA"}
					</Button>
				)}
			</div>

			<div className="flex flex-wrap gap-2 text-xs text-zinc-500">
				<Text>
					Origen actual del PDF:{" "}
					<Strong>{resultsPdf.pdf_source_label ?? "Sin PDF"}</Strong>
				</Text>
				<Text>
					Notificaciones de resultados:{" "}
					<Strong>{resultsPdf.results_notifications_count ?? 0}</Strong>
				</Text>
			</div>

		{message && (
			<Text className="text-xs text-emerald-600 dark:text-emerald-400">{message}</Text>
		)}

		{warning && (
			<div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
				<Text className="text-sm text-amber-900 dark:text-amber-100">
					<Strong>Respuesta de GDA en este intento:</Strong>
				</Text>
				<Text className="mt-1 text-xs text-amber-800 dark:text-amber-200">
					{warning}
				</Text>
				{resultsPdf.has_pdf_in_storage && (
					<Text className="mt-2 text-xs text-amber-700 dark:text-amber-300">
						Aun así tienes PDF en storage/S3 (última sync:{" "}
						{formatDateTime(resultsPdf.last_sync_at)}). Usa{" "}
						<Strong>Descargar PDF</Strong> para validar el contenido.
					</Text>
				)}
			</div>
		)}

		{error && (
			<Text className="text-xs text-red-600 dark:text-red-400">{error}</Text>
		)}
		</div>
	);
}
