import axios from "axios";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
	ArrowPathIcon,
	BeakerIcon,
	CheckCircleIcon,
	DocumentTextIcon,
	EyeIcon,
} from "@heroicons/react/24/outline";
import SettingsLayout from "@/Layouts/SettingsLayout";
import Card from "@/Components/Card";
import Purchase from "@/Components/Purchase";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Strong, Text } from "@/Components/Catalyst/text";

const normalizeBoolean = (value) => {
	if (typeof value === "boolean") return value;
	if (typeof value === "number") return value === 1;
	if (typeof value === "string") {
		const normalized = value.toLowerCase().trim();
		return normalized === "1" || normalized === "true";
	}
	return false;
};

const openPdfFromBase64 = (pdfBase64) => {
	const byteCharacters = atob(pdfBase64);
	const byteNumbers = new Array(byteCharacters.length);

	for (let i = 0; i < byteCharacters.length; i++) {
		byteNumbers[i] = byteCharacters.charCodeAt(i);
	}

	const byteArray = new Uint8Array(byteNumbers);
	const blob = new Blob([byteArray], { type: "application/pdf" });
	const blobUrl = window.URL.createObjectURL(blob);
	window.open(blobUrl, "_blank", "noopener,noreferrer");
};

export default function LaboratoryPurchase({
	laboratoryPurchase,
	hasSampleCollected = false,
	hasResultsAvailable = false,
	latestSampleCollectionAt = null,
	latestResultsAt = null,
	hasResultsPdfCached = false,
}) {
	const [loadingAutomaticResults, setLoadingAutomaticResults] = useState(false);
	const [automaticResultsError, setAutomaticResultsError] = useState("");

	const hasManualResults = Boolean(laboratoryPurchase?.results);
	const hasSample = normalizeBoolean(hasSampleCollected);
	const hasAutomaticResults = normalizeBoolean(hasResultsAvailable);
	const hasCachedAutomaticResults = normalizeBoolean(hasResultsPdfCached);

	const shouldShowAutomaticFlow = !hasManualResults;
	const shouldShowSampleBadge = shouldShowAutomaticFlow && hasSample && !hasAutomaticResults;
	const shouldShowAutomaticResults = shouldShowAutomaticFlow && hasAutomaticResults;

	useEffect(() => {
		if (hasManualResults) return undefined;

		const intervalId = window.setInterval(() => {
			router.reload({
				only: [
					"hasSampleCollected",
					"hasResultsAvailable",
					"latestSampleCollectionAt",
					"latestResultsAt",
					"hasResultsPdfCached",
				],
				preserveState: true,
				preserveScroll: true,
			});
		}, 30000);

		return () => window.clearInterval(intervalId);
	}, [hasManualResults]);

	const fetchAutomaticResults = async () => {
		if (loadingAutomaticResults) return;

		setLoadingAutomaticResults(true);
		setAutomaticResultsError("");

		try {
			const url = route("laboratory-purchases.results.automatic-fetch", {
				laboratoryPurchase: laboratoryPurchase.id,
			});
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

			const response = await axios.post(
				url,
				{},
				{
					withCredentials: true,
					headers: {
						"X-CSRF-TOKEN": csrfToken,
						Accept: "application/json",
					},
				},
			);

			if (response?.data?.success && response?.data?.pdf_base64) {
				openPdfFromBase64(response.data.pdf_base64);

				router.reload({
					only: ["hasResultsPdfCached", "hasResultsAvailable", "latestResultsAt"],
					preserveState: true,
					preserveScroll: true,
				});
				return;
			}

			setAutomaticResultsError(
				response?.data?.message || "No se pudieron obtener los resultados automáticos",
			);
		} catch (error) {
			if (error?.response?.status === 404) {
				setAutomaticResultsError("Resultados automáticos aún no disponibles");
			} else {
				setAutomaticResultsError(
					error?.response?.data?.message ||
						"No fue posible consultar los resultados automáticos",
				);
			}
		} finally {
			setLoadingAutomaticResults(false);
		}
	};

	return (
		<SettingsLayout title="Detalle del pedido">
			<div className="space-y-6 sm:space-y-8">
				<Card className="space-y-8 p-6 lg:space-y-10 lg:p-12">
					<Purchase purchase={laboratoryPurchase} isLabPurchase />
				</Card>

				<Card className="space-y-4 p-6 lg:p-8">
					<Subheading>Estado de resultados</Subheading>

					{hasManualResults && (
						<div className="space-y-3">
							<Badge color="sky" className="justify-start">
								<DocumentTextIcon className="size-4" />
								Resultados cargados manualmente
							</Badge>
							<Text className="text-sm text-slate-500">
								Este pedido tiene resultados manuales.
							</Text>
						</div>
					)}

					{shouldShowSampleBadge && (
						<div className="space-y-2">
							<Badge color="amber" className="justify-start">
								<BeakerIcon className="size-4" />
								Muestra tomada en laboratorio
							</Badge>
							{latestSampleCollectionAt && (
								<Text className="text-xs text-slate-500">{latestSampleCollectionAt}</Text>
							)}
						</div>
					)}

					{shouldShowAutomaticResults && (
						<div className="space-y-3">
							<Badge color="emerald" className="justify-start">
								<CheckCircleIcon className="size-4" />
								{hasCachedAutomaticResults
									? "Resultados disponibles"
									: "Resultados automáticos disponibles"}
							</Badge>

							{latestResultsAt && (
								<Text className="text-xs text-slate-500">{latestResultsAt}</Text>
							)}

							<div>
								<Button
									onClick={fetchAutomaticResults}
									disabled={loadingAutomaticResults}
									outline
								>
									{loadingAutomaticResults ? (
										<>
											<ArrowPathIcon className="size-4 animate-spin" />
											Consultando...
										</>
									) : (
										<>
											<EyeIcon className="size-4" />
											{hasCachedAutomaticResults ? "Ver PDF" : "Ver resultados"}
										</>
									)}
								</Button>
							</div>
						</div>
					)}

					{!hasManualResults && !shouldShowSampleBadge && !shouldShowAutomaticResults && (
						<Text className="text-sm text-slate-500">
							Pedido en proceso. Aún no hay notificaciones de muestra ni resultados.
						</Text>
					)}

					{automaticResultsError && (
						<Text className="text-sm text-red-600">
							<Strong>Error:</Strong> {automaticResultsError}
						</Text>
					)}
				</Card>
			</div>
		</SettingsLayout>
	);
}
