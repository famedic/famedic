import {
	EllipsisVerticalIcon,
	ArrowPathIcon,
	ArrowTopRightOnSquareIcon,
	DocumentTextIcon,
	EyeIcon,
	LockClosedIcon,
} from "@heroicons/react/24/outline";
import { Button } from "@/Components/Catalyst/button";
import { Dropdown, DropdownButton, DropdownMenu, DropdownItem } from "@/Components/Catalyst/dropdown";
import { getPrimaryPurchaseAction, purchaseHasResults, purchaseIsCancelled } from "@/lib/laboratoryPurchaseOrderUi";
import { useState } from "react";
import { openLabResultsUrl } from "@/Utils/openLabResultsUrl";

export default function OrderRowActions({ purchase, beginProtectedUrl, layout = "row" }) {
	const isMobile = layout === "mobile";
	const isMenuOnly = layout === "menu-only";
	const [isProcessingResults, setIsProcessingResults] = useState(false);
	const isCancelled = purchaseIsCancelled(purchase);
	const primary = getPrimaryPurchaseAction(purchase);
	const hasResults = purchaseHasResults(purchase);

	const viewResultsLabel =
		purchase.result_source === "manual"
			? "Ver resultados"
			: purchase.result_source === "api"
				? "Ver resultados del laboratorio"
				: "Ver resultados";

	const resolveResultsUrl = () => {
		if (purchase.result_source === "api") {
			return purchase.api_result_url || purchase.result_view_url;
		}
		return purchase.result_view_url;
	};

	const handleViewResults = () => {
		if (isProcessingResults) return;

		const url = resolveResultsUrl();
		if (!url) return;

		setIsProcessingResults(true);

		if (typeof beginProtectedUrl === "function") {
			void beginProtectedUrl(purchase.id, url).finally(() => setIsProcessingResults(false));
			return;
		}

		openLabResultsUrl(url);
		setIsProcessingResults(false);
	};

	const btnBase = isMobile
		? "min-h-12 w-full justify-center text-base font-semibold"
		: "min-h-10 whitespace-nowrap px-3 text-sm";

	const primaryButton =
		primary.key === "results" ? (
			<Button
				outline
				className={btnBase}
				onClick={handleViewResults}
				disabled={isProcessingResults}
				title={isProcessingResults ? "Estamos validando tu identidad..." : undefined}
			>
				{isProcessingResults ? (
					<ArrowPathIcon className="mr-1.5 size-4 shrink-0 animate-spin sm:size-5" />
				) : (
					<EyeIcon className="mr-1.5 size-4 shrink-0 sm:size-5" />
				)}
				{isProcessingResults ? "Validando..." : primary.label}
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

	const stopRowNavigation = (event) => {
		event.stopPropagation();
	};

	return (
		<div
			className={wrapClass}
			onClick={stopRowNavigation}
			onPointerDown={stopRowNavigation}
			onKeyDown={stopRowNavigation}
		>
			{!isMenuOnly && isMobile ? (
				<div className="min-w-0 flex-1">
					{primaryButton}
				</div>
			) : !isMenuOnly ? (
				<div className="min-w-0">
					{primaryButton}
					{primary.key === "results" && (
						<p
							className="mt-1 flex items-center gap-1 text-xs text-zinc-500 dark:text-slate-400"
							title="Tus resultados están protegidos. Te pediremos un código OTP."
						>
							<LockClosedIcon className="size-3.5" />
							Requiere verificación OTP
						</p>
					)}
				</div>
			) : null}
			<Dropdown>
				<DropdownButton
					outline
					aria-label="Más acciones"
					onClick={stopRowNavigation}
					onPointerDown={stopRowNavigation}
					className={isMobile ? "min-h-12 min-w-12 shrink-0 justify-center px-0" : "min-h-10 min-w-10 justify-center px-0 sm:px-2"}
				>
					<EllipsisVerticalIcon className="size-5" />
				</DropdownButton>
				<DropdownMenu anchor="bottom end">
					<DropdownItem href={purchase.show_detail_url}>
						<DocumentTextIcon data-slot="icon" />
						Ver pedido
					</DropdownItem>
					{hasResults && (
						<DropdownItem onClick={handleViewResults} disabled={isProcessingResults}>
							<EyeIcon data-slot="icon" />
							{isProcessingResults ? "Validando..." : viewResultsLabel}
						</DropdownItem>
					)}
					{purchase.invoice_url ? (
						<DropdownItem href={purchase.invoice_url}>
							<DocumentTextIcon data-slot="icon" />
							Ver factura
						</DropdownItem>
					) : !purchase.invoice_requested && !isCancelled ? (
						<DropdownItem href={purchase.invoice_request_url || `${purchase.show_detail_url}?tab=facturas`}>
							<DocumentTextIcon data-slot="icon" />
							Solicitar factura
						</DropdownItem>
					) : null}
				</DropdownMenu>
			</Dropdown>
		</div>
	);
}
