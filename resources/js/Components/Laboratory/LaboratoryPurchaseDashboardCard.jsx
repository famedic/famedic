import { Link } from "@inertiajs/react";
import {
	ArrowTopRightOnSquareIcon,
	ArrowDownTrayIcon,
	DocumentTextIcon,
	EyeIcon,
	QrCodeIcon,
} from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";

const TAG_STYLES = {
	cancelled:
		"bg-red-100 text-red-900 ring-red-200 dark:bg-red-950/40 dark:text-red-100 dark:ring-red-800",
	new_result:
		"bg-emerald-100 text-emerald-900 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-800",
	result_manual:
		"bg-slate-100 text-slate-800 ring-slate-200 dark:bg-slate-800/80 dark:text-slate-100 dark:ring-slate-600",
	result_api:
		"bg-violet-100 text-violet-900 ring-violet-200 dark:bg-violet-950/40 dark:text-violet-100 dark:ring-violet-800",
	sample:
		"bg-sky-100 text-sky-900 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-100 dark:ring-sky-800",
	invoice_ok:
		"bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-800",
	invoice_req:
		"bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-zinc-800/80 dark:text-zinc-200 dark:ring-zinc-600",
};

const MAX_TAGS = 4;

function buildTags(purchase) {
	const tags = [];

	if (purchase.study_status === "cancelled") {
		tags.push({
			key: "cancelled",
			style: TAG_STYLES.cancelled,
			content: (
				<>
					<span aria-hidden>❌</span> Cancelado
				</>
			),
		});
		return tags;
	}

	if (purchase.is_new_result) {
		tags.push({
			key: "new_result",
			style: TAG_STYLES.new_result,
			content: (
				<>
					<span aria-hidden>🟢</span> Nuevo resultado
				</>
			),
		});
	}

	if (purchase.result_source === "manual") {
		tags.push({
			key: "result_manual",
			style: TAG_STYLES.result_manual,
			content: (
				<>
					<span aria-hidden>💻</span> Resultado cargado
				</>
			),
		});
	} else if (purchase.result_source === "api") {
		tags.push({
			key: "result_api",
			style: TAG_STYLES.result_api,
			content: (
				<>
					<span aria-hidden>🔄</span> Resultado sincronizado
				</>
			),
		});
	}

	if (purchase.invoice_url) {
		tags.push({
			key: "invoice_ok",
			style: TAG_STYLES.invoice_ok,
			content: (
				<>
					<span aria-hidden>🧾</span> Factura disponible
				</>
			),
		});
	} else if (purchase.invoice_requested) {
		tags.push({
			key: "invoice_req",
			style: TAG_STYLES.invoice_req,
			content: (
				<>
					<span aria-hidden>📝</span> Factura solicitada
				</>
			),
		});
	}

	if (purchase.has_sample_notification) {
		tags.push({
			key: "sample",
			style: TAG_STYLES.sample,
			content: (
				<>
					<span aria-hidden>💉</span> Muestra tomada
				</>
			),
		});
	}

	return tags.slice(0, MAX_TAGS);
}

function openExternal(url) {
	if (!url) return;
	window.open(url, "_blank", "noopener,noreferrer");
}

export default function LaboratoryPurchaseDashboardCard({ purchase }) {
	const canViewResults = Boolean(purchase.result_view_url);
	const canDownloadPdf =
		typeof purchase.can_download_pdf === "boolean"
			? purchase.can_download_pdf
			: purchase.result_source === "manual"
				? Boolean(purchase.pdf_url)
				: purchase.result_source === "api"
					? Boolean(purchase.results_pdf_base64_available)
					: false;

	const viewResultsLabel =
		purchase.result_source === "manual"
			? "Ver resultados cargados"
			: purchase.result_source === "api"
				? "Ver resultados del laboratorio"
				: "Ver resultados";

	const downloadLabel =
		purchase.result_source === "api"
			? "Descargar desde laboratorio"
			: "Descargar PDF";

	const studiesCount =
		typeof purchase.studies_count === "number"
			? purchase.studies_count
			: purchase.items_count ?? 0;
	const studiesLabel =
		studiesCount === 1 ? "1 estudio incluido" : `${studiesCount} estudios incluidos`;

	const tags = buildTags(purchase);

	const handleViewResults = () => {
		if (!purchase.result_view_url) return;
		if (purchase.result_source === "manual") {
			openExternal(purchase.result_view_url);
		} else if (purchase.result_source === "api") {
			openExternal(purchase.api_result_url || purchase.result_view_url);
		} else {
			openExternal(purchase.result_view_url);
		}
	};

	const handleDownload = () => {
		if (purchase.result_source === "manual") {
			openExternal(purchase.result_download_url || purchase.pdf_url);
		} else {
			openExternal(purchase.result_download_url);
		}
	};

	const showFolio =
		!purchase.temporarly_hide_gda_order_id && Boolean(purchase.gda_order_id);
	const gdaConsecutivo =
		purchase.gda_consecutivo != null && purchase.gda_consecutivo !== ""
			? String(purchase.gda_consecutivo)
			: null;

	return (
		<article className="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">
			<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div className="min-w-0 flex-1 space-y-3">
					<Text className="text-lg font-semibold leading-snug text-zinc-900 dark:text-white sm:text-xl">
						{purchase.study_name}
					</Text>

					{tags.length > 0 && (
						<div className="flex flex-wrap items-center gap-2">
							{tags.map((tag) => (
								<span
									key={tag.key}
									className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset sm:text-sm ${tag.style}`}
								>
									{tag.content}
								</span>
							))}
						</div>
					)}

					<Text className="text-base text-zinc-800 dark:text-slate-200">
						Paciente: <Strong>{purchase.patient_name}</Strong>
					</Text>

					{(showFolio || gdaConsecutivo) && (
						<div className="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-slate-600 dark:bg-slate-800/60">
							{showFolio && (
								<div className="flex flex-wrap items-center gap-3">
									<div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-2">
										<span className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
											Folio
										</span>
										<span className="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">
											{purchase.gda_order_id}
										</span>
									</div>
									<QrCodeIcon
										className="size-9 shrink-0 text-zinc-400 dark:text-slate-500"
										aria-hidden
									/>
								</div>
							)}
							{gdaConsecutivo && (
								<div
									className={`flex flex-wrap items-baseline gap-2 ${showFolio ? "border-t border-zinc-200 pt-2 dark:border-slate-600" : ""}`}
								>
									<span className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
										GDA
									</span>
									<span className="text-lg font-semibold text-zinc-900 dark:text-white">
										{gdaConsecutivo}
									</span>
								</div>
							)}
						</div>
					)}

					<Text className="text-base text-zinc-600 dark:text-slate-400">{studiesLabel}</Text>

					<div className="flex flex-col gap-1 border-t border-zinc-100 pt-3 text-sm text-zinc-600 dark:border-slate-800 dark:text-slate-400">
						<span>Pedido: {purchase.purchased_at_formatted}</span>
						<span>Laboratorio: {purchase.laboratory_name}</span>
						<span>Forma de pago: {purchase.payment_method_label}</span>
						<span>Total: {purchase.formatted_total}</span>
					</div>
				</div>
				<img
					src={`/images/gda/GDA-${String(purchase.laboratory_brand_value || "").toUpperCase()}.png`}
					alt=""
					className="mx-auto h-14 w-auto shrink-0 object-contain sm:mx-0 sm:h-16"
				/>
			</div>

			<div className="flex flex-col gap-3 border-t border-zinc-100 pt-4 dark:border-slate-800 sm:flex-row sm:flex-wrap">
				{canViewResults && (
					<Button
						outline
						className="min-h-[48px] flex-1 justify-center text-base font-semibold"
						onClick={handleViewResults}
					>
						<EyeIcon className="mr-2 size-5" />
						{viewResultsLabel}
					</Button>
				)}
				{canDownloadPdf && (
					<Button
						outline
						className="min-h-[48px] flex-1 justify-center text-base font-semibold"
						onClick={handleDownload}
					>
						<ArrowDownTrayIcon className="mr-2 size-5" />
						{downloadLabel}
					</Button>
				)}
				{purchase.invoice_url ? (
					<Button
						outline
						href={purchase.invoice_url}
						className="min-h-[48px] flex-1 justify-center text-base font-semibold"
					>
						<DocumentTextIcon className="mr-2 size-5" />
						Ver factura
					</Button>
				) : purchase.invoice_requested ? (
					<Button
						outline
						href={purchase.show_detail_url}
						className="min-h-[48px] flex-1 justify-center text-base font-semibold"
					>
						<DocumentTextIcon className="mr-2 size-5" />
						Factura solicitada — ver estado
					</Button>
				) : (
					<Button
						outline
						href={purchase.invoice_request_url || `${purchase.show_detail_url}?tab=facturas`}
						className="min-h-[48px] flex-1 justify-center text-base font-semibold"
					>
						<DocumentTextIcon className="mr-2 size-5" />
						Solicitar factura
					</Button>
				)}
				<Link
					href={purchase.show_detail_url}
					className="inline-flex min-h-[48px] flex-1 items-center justify-center rounded-lg border border-zinc-300 px-4 text-base font-semibold text-zinc-800 hover:bg-zinc-50 dark:border-slate-600 dark:text-white dark:hover:bg-slate-800"
				>
					<ArrowTopRightOnSquareIcon className="mr-2 size-5" />
					Ver pedido completo
				</Link>
			</div>
		</article>
	);
}
