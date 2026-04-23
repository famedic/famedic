import {
	EllipsisVerticalIcon,
	ArrowTopRightOnSquareIcon,
	ArrowDownTrayIcon,
	DocumentTextIcon,
	EyeIcon,
} from "@heroicons/react/24/outline";
import { Button } from "@/Components/Catalyst/button";
import { Dropdown, DropdownButton, DropdownMenu, DropdownItem, DropdownDivider } from "@/Components/Catalyst/dropdown";
import { getPrimaryPurchaseAction, purchaseHasResults } from "@/lib/laboratoryPurchaseOrderUi";

function openExternal(url) {
	if (!url) return;
	window.open(url, "_blank", "noopener,noreferrer");
}

export default function OrderRowActions({ purchase, requireOtpThen, layout = "row" }) {
	const isMobile = layout === "mobile";
	const primary = getPrimaryPurchaseAction(purchase);
	const hasResults = purchaseHasResults(purchase);
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

	const handleViewResults = async () => {
		if (!purchase.result_view_url) return;
		const run = () => {
			if (purchase.result_source === "manual") {
				openExternal(purchase.result_view_url);
			} else if (purchase.result_source === "api") {
				openExternal(purchase.api_result_url || purchase.result_view_url);
			} else {
				openExternal(purchase.result_view_url);
			}
		};
		if (typeof requireOtpThen === "function") {
			await requireOtpThen(purchase.id, run);
		} else {
			run();
		}
	};

	const handleDownload = async () => {
		const run = () => {
			if (purchase.result_source === "manual") {
				openExternal(purchase.result_download_url || purchase.pdf_url);
			} else {
				openExternal(purchase.result_download_url);
			}
		};
		if (typeof requireOtpThen === "function") {
			await requireOtpThen(purchase.id, run);
		} else {
			run();
		}
	};

	const btnBase = isMobile
		? "min-h-12 w-full justify-center text-base font-semibold"
		: "min-h-10 whitespace-nowrap px-3 text-sm";

	const primaryButton =
		primary.key === "results" ? (
			<Button outline className={btnBase} onClick={handleViewResults}>
				<EyeIcon className="mr-1.5 size-4 shrink-0 sm:size-5" />
				{primary.label}
			</Button>
		) : primary.href ? (
			<Button outline href={primary.href} className={btnBase}>
				{primary.key === "invoice" ? (
					<DocumentTextIcon className="mr-1.5 size-4 shrink-0 sm:size-5" />
				) : (
					<ArrowTopRightOnSquareIcon className="mr-1.5 size-4 shrink-0 sm:size-5" />
				)}
				{primary.label}
			</Button>
		) : (
			<Button outline href={purchase.show_detail_url} className={btnBase}>
				<ArrowTopRightOnSquareIcon className="mr-1.5 size-4 shrink-0 sm:size-5" />
				{primary.label}
			</Button>
		);

	const wrapClass = isMobile
		? "flex w-full items-stretch gap-2"
		: "flex flex-wrap items-center justify-end gap-2";

	return (
		<div className={wrapClass}>
			{isMobile ? <div className="min-w-0 flex-1">{primaryButton}</div> : primaryButton}
			<Dropdown>
				<DropdownButton
					outline
					aria-label="Más acciones"
					className={isMobile ? "min-h-12 min-w-12 shrink-0 justify-center px-0" : "min-h-10 min-w-10 justify-center px-0 sm:px-2"}
				>
					<EllipsisVerticalIcon className="size-5" />
				</DropdownButton>
				<DropdownMenu anchor="bottom end">
					<DropdownItem href={purchase.show_detail_url}>
						<ArrowTopRightOnSquareIcon data-slot="icon" />
						Ver pedido completo
					</DropdownItem>
					{hasResults && primary.key !== "results" && (
						<DropdownItem onClick={handleViewResults}>
							<EyeIcon data-slot="icon" />
							{viewResultsLabel}
						</DropdownItem>
					)}
					{canDownloadPdf && (
						<DropdownItem onClick={handleDownload}>
							<ArrowDownTrayIcon data-slot="icon" />
							Descargar PDF
						</DropdownItem>
					)}
					<DropdownDivider />
					{purchase.invoice_url ? (
						<DropdownItem href={purchase.invoice_url}>
							<DocumentTextIcon data-slot="icon" />
							Ver factura
						</DropdownItem>
					) : purchase.invoice_requested ? (
						<DropdownItem href={purchase.show_detail_url}>
							<DocumentTextIcon data-slot="icon" />
							Estado de factura
						</DropdownItem>
					) : (
						<DropdownItem href={purchase.invoice_request_url || `${purchase.show_detail_url}?tab=facturas`}>
							<DocumentTextIcon data-slot="icon" />
							Solicitar factura
						</DropdownItem>
					)}
				</DropdownMenu>
			</Dropdown>
		</div>
	);
}
