import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

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

export default function LaboratoryNotificationResultsPdfActions({
	orderKey,
	resultsPdf,
	onResultsPdfUpdated,
}) {
	const [fetching, setFetching] = useState(false);
	const [downloading, setDownloading] = useState(false);
	const [message, setMessage] = useState(null);
	const [error, setError] = useState(null);

	const canFetchFromGda = resultsPdf?.available_at_gda && !resultsPdf?.has_pdf_in_db;
	const canDownloadFromDb = resultsPdf?.has_pdf_in_db;

	const fetchFromGda = async () => {
		setFetching(true);
		setMessage(null);
		setError(null);

		try {
			const response = await fetch(
				route("admin.laboratory-notifications-monitor.fetch-results", {
					orderKey,
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
				},
			);

			const json = await response.json();

			if (!response.ok || !json.success) {
				throw new Error(json.message || "No se pudo obtener el PDF desde GDA.");
			}

			setMessage(json.message);
			onResultsPdfUpdated?.(json.results_pdf);

			if (json.pdf_base64) {
				openPdfFromBase64(json.pdf_base64);
			}
		} catch (err) {
			setError(
				err instanceof Error
					? err.message
					: "No se pudo obtener el PDF desde GDA.",
			);
		} finally {
			setFetching(false);
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

			setMessage("Descarga simulada desde la base de datos completada.");
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

	if (!canFetchFromGda && !canDownloadFromDb) {
		return null;
	}

	return (
		<div className="space-y-2">
			<div className="flex flex-wrap gap-2">
				{canFetchFromGda && (
					<Button color="sky" onClick={fetchFromGda} disabled={fetching}>
						{fetching ? "Consultando GDA..." : "Obtener PDF desde GDA"}
					</Button>
				)}
				{canDownloadFromDb && (
					<Button outline onClick={downloadFromDb} disabled={downloading}>
						{downloading ? "Descargando..." : "Descargar PDF (BD)"}
					</Button>
				)}
			</div>

			{message && (
				<Text className="text-xs text-emerald-600 dark:text-emerald-400">
					{message}
				</Text>
			)}

			{error && (
				<Text className="text-xs text-red-600 dark:text-red-400">{error}</Text>
			)}
		</div>
	);
}
