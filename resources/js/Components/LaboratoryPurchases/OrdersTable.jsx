import { router } from "@inertiajs/react";
import {
	ArrowPathIcon,
	CheckCircleIcon,
	ClockIcon,
	NoSymbolIcon,
	QrCodeIcon,
} from "@heroicons/react/24/solid";
import { Table, TableHead, TableHeader, TableBody, TableRow, TableCell } from "@/Components/Catalyst/table";
import { Text, Strong } from "@/Components/Catalyst/text";
import OrderRowActions from "@/Components/LaboratoryPurchases/OrderRowActions";
import { purchaseHasResults } from "@/lib/laboratoryPurchaseOrderUi";
import PaymentMethodDisplayIcon from "@/Components/PaymentMethodDisplayIcon";
import { useState } from "react";

function studiesExtraCount(purchase) {
	const n = typeof purchase.studies_count === "number" ? purchase.studies_count : purchase.items_count ?? 0;
	return n > 1 ? n - 1 : 0;
}

function toTitleCase(value = "") {
	return String(value)
		.toLowerCase()
		.split(" ")
		.filter(Boolean)
		.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
		.join(" ");
}

function onlyDateLabel(value = "") {
	const raw = String(value || "").trim();
	if (!raw) return "Sin fecha";
	const parts = raw.split(" ");
	return parts.slice(0, 3).join(" ");
}

function laboratoryLogoSrc(purchase) {
	const brand = String(purchase.laboratory_brand_value || "GDA").toUpperCase();
	return `/images/gda/GDA-${brand}.png`;
}

function StatusBullet({ state }) {
	if (state === "available") {
		return (
			<CheckCircleIcon className="size-5 text-lime-500" />
		);
	}
	if (state === "in_progress") {
		return (
			<ClockIcon className="size-5 text-lime-500" />
		);
	}
	return <NoSymbolIcon className="size-5 text-slate-500" />;
}

function statusTooltip(state) {
	if (state === "available") return "Disponible";
	if (state === "in_progress") return "En proceso";
	return "No solicitado";
}

function getStatusMap(purchase) {
	const hasInvoice = Boolean(purchase.has_invoice);
	const invoiceRequested = Boolean(purchase.invoice_requested);
	const requiresAppointment = Boolean(purchase.requires_appointment);
	const hasAppointment = Boolean(purchase.has_appointment_scheduled);
	const hasSample = Boolean(purchase.has_sample_notification);
	const hasResults = purchaseHasResults(purchase);

	return {
		factura: hasInvoice ? "available" : invoiceRequested ? "in_progress" : "no_requested",
		cita: !requiresAppointment ? "no_requested" : hasAppointment ? "available" : "in_progress",
		muestra: hasResults
			? "available"
			: hasSample
				? "available"
				: hasAppointment || !requiresAppointment
					? "in_progress"
					: "no_requested",
		resultados: hasResults ? "available" : hasSample ? "in_progress" : "no_requested",
	};
}

export default function OrdersTable({ purchases, requireOtpThen }) {
	const [loadingRowId, setLoadingRowId] = useState(null);

	const openPurchaseDetail = (purchase) => {
		if (!purchase?.show_detail_url || loadingRowId !== null) return;
		setLoadingRowId(purchase.id);
		router.get(purchase.show_detail_url, {}, { onFinish: () => setLoadingRowId(null) });
	};

	return (
		<div className="hidden md:block">
			<div className="overflow-hidden rounded-2xl border border-zinc-200/90 bg-white/60 shadow-sm dark:border-slate-700/90 dark:bg-slate-900/40 dark:shadow-none">
				<Table bleed dense>
					<TableHead>
						<TableRow>
							<TableHeader>Paciente y estudios</TableHeader>
							<TableHeader>Laboratorio y folios</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="text-right">Acciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{purchases.map((purchase) => {
							const showFolio = !purchase.temporarly_hide_gda_order_id && Boolean(purchase.gda_order_id);
							const extraStudies = studiesExtraCount(purchase);
							const statusMap = getStatusMap(purchase);
							const patientName = toTitleCase(purchase.patient_name);
							const gdaConsecutivo =
								purchase.gda_consecutivo != null && purchase.gda_consecutivo !== ""
									? String(purchase.gda_consecutivo)
									: null;
							const isLoading = loadingRowId === purchase.id;
							return (
								<TableRow
									key={purchase.id}
									title="Ver pedido completo"
									className={isLoading ? "opacity-70" : "cursor-pointer"}
									tabIndex={0}
									role="link"
									aria-label="Ver pedido completo"
									onClick={() => openPurchaseDetail(purchase)}
									onKeyDown={(event) => {
										if (event.key === "Enter" || event.key === " ") {
											event.preventDefault();
											openPurchaseDetail(purchase);
										}
									}}
								>
									<TableCell>
										<div className="flex flex-wrap items-start gap-2">
											<div className="min-w-0 flex-1">
												<Text className="font-semibold text-zinc-900 dark:text-white">
													{patientName}
												</Text>
												<Text className="mt-0.5 text-sm text-zinc-600 dark:text-slate-300">
													{purchase.study_name}
												</Text>
												<Text className="mt-1 text-sm">
													<Strong className="text-zinc-900 dark:text-white">{purchase.formatted_total}</Strong>
												</Text>
												<div className="mt-1">
													<PaymentMethodDisplayIcon
														method={purchase.payment_method}
														label={purchase.payment_method_label}
														size="sm"
													/>
												</div>
											</div>
											{extraStudies > 0 && (
												<span
													className="shrink-0 rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200/80 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600"
													title={`${(purchase.studies_count ?? purchase.items_count) || 0} estudios en el pedido`}
												>
													+{extraStudies}
												</span>
											)}
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-start gap-3">
											<img
												src={laboratoryLogoSrc(purchase)}
												alt=""
												className="mt-0.5 h-16 w-auto max-w-[6rem] shrink-0 rounded-lg object-contain"
											/>
											<div className="min-w-0 space-y-1.5">
												<Text className="text-xs font-medium uppercase tracking-wide text-zinc-500">
													{onlyDateLabel(purchase.purchased_at_formatted)}
												</Text>
												<div>
													{showFolio ? (
														<span className="inline-flex items-center gap-1.5 rounded-full bg-lime-500/15 px-2 py-0.5 font-mono text-sm font-semibold text-lime-500">
															<QrCodeIcon
																className="size-3.5 shrink-0"
																aria-hidden
															/>
															<span>{purchase.gda_order_id}</span>
														</span>
													) : null}
													{showFolio && gdaConsecutivo ? <br /> : null}
													{gdaConsecutivo ? (
														<span className="inline-flex items-center gap-1.5 rounded-full bg-blue-500/15 px-2 py-0.5 font-mono text-sm font-semibold text-blue-500">
															<QrCodeIcon
																className="size-3.5 shrink-0"
																aria-hidden
															/>
															<span>{gdaConsecutivo}</span>
														</span>
													) : null}
												</div>
												{!showFolio && !gdaConsecutivo ? (
													<Text className="text-sm text-zinc-400 dark:text-slate-500">—</Text>
												) : null}
											</div>
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-2 text-xs">
											<div className="flex items-center justify-between gap-2">
												<Text className="text-[11px] tracking-wide text-zinc-500">Cita</Text>
												<span title={`Cita: ${statusTooltip(statusMap.cita)}`}>
													<StatusBullet state={statusMap.cita} />
												</span>
											</div>
											<div className="flex items-center justify-between gap-2">
												<Text className="text-[11px] tracking-wide text-zinc-500">Muestras</Text>
												<span title={`Toma de muestra: ${statusTooltip(statusMap.muestra)}`}>
													<StatusBullet state={statusMap.muestra} />
												</span>
											</div>
											<div className="flex items-center justify-between gap-2">
												<Text className="text-[11px] tracking-wide text-zinc-500">Resultados</Text>
												<span title={`Resultados: ${statusTooltip(statusMap.resultados)}`}>
													<StatusBullet state={statusMap.resultados} />
												</span>
											</div>
											<div className="flex items-center justify-between gap-2">
												<Text className="text-[11px] tracking-wide text-zinc-500">Factura</Text>
												<span title={`Factura: ${statusTooltip(statusMap.factura)}`}>
													<StatusBullet state={statusMap.factura} />
												</span>
											</div>
										</div>
									</TableCell>
									<TableCell className="text-right">
										<div className="relative z-10 flex justify-end">
											{isLoading ? (
												<span className="inline-flex items-center gap-2 text-xs font-semibold text-zinc-600 dark:text-slate-300">
													<ArrowPathIcon className="size-4 animate-spin" />
													...
												</span>
											) : (
												<OrderRowActions
													purchase={purchase}
													requireOtpThen={requireOtpThen}
													layout="menu-only"
												/>
											)}
										</div>
									</TableCell>
								</TableRow>
							);
						})}
					</TableBody>
				</Table>
			</div>
		</div>
	);
}
