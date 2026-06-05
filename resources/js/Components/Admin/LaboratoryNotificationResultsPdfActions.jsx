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
		throw new Error(json.message || "La operación no se completó.");
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
	const canDownloadFromDb = Boolean(resultsPdf.can_download_from_db);

	const handleSuccess = (json) => {
		setMessage(json.message);
		onResultsPdfUpdated?.(json.results_pdf);
		if (json.pdf_base64) {
			openPdfFromBase64(json.pdf_base64);
		}
	};

	const fetchFromGda = async () => {
		setFetching(true);
		setMessage(null);
		setError(null);

		try {
			handleSuccess(
				await postJson("admin.laboratory-notifications-monitor.fetch-results", orderKey),
			);
		} catch (err) {
			setError(err instanceof Error ? err.message : "No se pudo obtener el PDF desde GDA.");
		} finally {
			setFetching(false);
		}
	};

	const forceRefreshFromGda = async () => {
		if (
			!window.confirm(
				"¿Forzar actualización desde GDA? Se limpiará el PDF cacheado en la BD y se consultará el resultado más reciente al laboratorio.",
			)
		) {
			return;
		}

		setForcing(true);
		setMessage(null);
		setError(null);

		try {
			handleSuccess(
				await postJson(
					"admin.laboratory-notifications-monitor.force-refresh-results",
					orderKey,
				),
			);
		} catch (err) {
			setError(
				err instanceof Error
					? err.message
					: "No se pudo forzar la actualización desde GDA.",
			);
		} finally {
			setForcing(false);
		}
	};

	const downloadFromDb = async () => {
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
				throw new Error("No se pudo descargar el PDF desde la base de datos.");
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

			setMessage("Descarga del PDF cacheado en BD completada.");
		} catch (err) {
			setError(
				err instanceof Error
					? err.message
					: "No se pudo descargar el PDF desde la base de datos.",
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
							<Strong>Hay resultados más recientes.</Strong> El PDF en BD puede estar
							desactualizado respecto a la última notificación de GDA.
						</Text>
					</div>
				)}
			</div>

			<div className="grid gap-2 text-sm sm:grid-cols-2">
				<Text>
					Origen actual del PDF:{" "}
					<Strong>{resultsPdf.pdf_source_label ?? "Sin PDF en BD"}</Strong>
				</Text>
				<Text>
					Notificaciones de resultados:{" "}
					<Strong>{resultsPdf.results_notifications_count ?? 0}</Strong>
				</Text>
				<Text>
					Última notificación GDA:{" "}
					<Strong>#{resultsPdf.latest_notification_id ?? "—"}</Strong>
				</Text>
				<Text>
					Notificación con PDF en BD:{" "}
					<Strong>#{resultsPdf.serving_notification_id ?? "—"}</Strong>
				</Text>
				<Text>
					Últimos resultados recibidos:{" "}
					<Strong>{formatDateTime(resultsPdf.latest_results_at)}</Strong>
				</Text>
				<Text>
					Última descarga vía API GDA:{" "}
					<Strong>{formatDateTime(resultsPdf.pdf_fetched_at)}</Strong>
				</Text>
			</div>

			<div className="flex flex-wrap gap-2">
				<Badge color={resultsPdf.has_pdf_in_db ? "famedic-lime" : "slate"}>
					En BD (base64): {resultsPdf.has_pdf_in_db ? "Sí" : "No"}
				</Badge>
				<Badge color={resultsPdf.available_at_gda ? "sky" : "slate"}>
					Disponible en GDA: {resultsPdf.available_at_gda ? "Sí" : "No"}
				</Badge>
				{resultsPdf.is_stale && (
					<Badge color="amber">Caché posiblemente desactualizada</Badge>
				)}
			</div>

			<div className="flex flex-wrap gap-2">
				{canFetchFromGda && (
					<Button color="sky" onClick={fetchFromGda} disabled={fetching || forcing}>
						{fetching ? "Consultando GDA..." : "Obtener PDF desde GDA"}
					</Button>
				)}
				{canForceRefresh && (
					<Button color="amber" onClick={forceRefreshFromGda} disabled={fetching || forcing}>
						{forcing ? "Actualizando..." : "Forzar actualización desde GDA"}
					</Button>
				)}
				{canDownloadFromDb && (
					<Button outline onClick={downloadFromDb} disabled={downloading}>
						{downloading ? "Descargando..." : "Descargar PDF (BD)"}
					</Button>
				)}
			</div>

			{message && (
				<Text className="text-xs text-emerald-600 dark:text-emerald-400">{message}</Text>
			)}

			{error && (
				<Text className="text-xs text-red-600 dark:text-red-400">{error}</Text>
			)}
		</div>
	);
}
