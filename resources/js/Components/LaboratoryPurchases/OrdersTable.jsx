import { Link } from "@inertiajs/react";
import { QrCodeIcon } from "@heroicons/react/24/solid";
import { Table, TableHead, TableHeader, TableBody, TableRow, TableCell } from "@/Components/Catalyst/table";
import { Text, Strong } from "@/Components/Catalyst/text";
import OrderRowActions from "@/Components/LaboratoryPurchases/OrderRowActions";
import { getOrderBadgePresentation } from "@/lib/laboratoryPurchaseOrderUi";
import PaymentMethodDisplayIcon from "@/Components/PaymentMethodDisplayIcon";
import clsx from "clsx";

function studiesExtraCount(purchase) {
	const n = typeof purchase.studies_count === "number" ? purchase.studies_count : purchase.items_count ?? 0;
	return n > 1 ? n - 1 : 0;
}

function laboratoryLogoSrc(purchase) {
	const brand = String(purchase.laboratory_brand_value || "GDA").toUpperCase();
	return `/images/gda/GDA-${brand}.png`;
}

function InvoiceCell({ purchase }) {
	if (purchase.invoice_url) {
		return (
			<Link
				href={purchase.invoice_url}
				className="relative z-10 font-medium text-famedic-dark underline-offset-2 hover:underline dark:text-famedic-light"
			>
				Ver factura
			</Link>
		);
	}
	if (purchase.invoice_requested) {
		return <span className="text-zinc-500 dark:text-slate-400">Solicitada</span>;
	}
	return <span className="text-zinc-400 dark:text-slate-500">—</span>;
}

export default function OrdersTable({ purchases, requireOtpThen }) {
	return (
		<div className="hidden md:block">
			<div className="overflow-hidden rounded-2xl border border-zinc-200/90 bg-white/60 shadow-sm dark:border-slate-700/90 dark:bg-slate-900/40 dark:shadow-none">
				<Table bleed dense>
					<TableHead>
						<TableRow>
							<TableHeader>Estudio y paciente</TableHeader>
							<TableHeader>Folio y laboratorio</TableHeader>
							<TableHeader>Compra</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Factura</TableHeader>
							<TableHeader className="text-right">Total</TableHeader>
							<TableHeader className="text-right">Acciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{purchases.map((purchase) => {
							const badge = getOrderBadgePresentation(purchase);
							const showFolio = !purchase.temporarly_hide_gda_order_id && Boolean(purchase.gda_order_id);
							const extraStudies = studiesExtraCount(purchase);
							const gdaConsecutivo =
								purchase.gda_consecutivo != null && purchase.gda_consecutivo !== ""
									? String(purchase.gda_consecutivo)
									: null;
							return (
								<TableRow key={purchase.id}>
									<TableCell>
										<div className="flex flex-wrap items-start gap-2">
											<div className="min-w-0 flex-1">
												<Text className="font-medium text-zinc-900 dark:text-white">{purchase.study_name}</Text>
												<Text className="mt-0.5 text-sm text-zinc-500 dark:text-slate-400">
													<Strong className="font-normal text-zinc-700 dark:text-slate-300">
														{purchase.patient_name}
													</Strong>
												</Text>
											</div>
											{extraStudies > 0 && (
												<span
													className="shrink-0 rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600 ring-1 ring-zinc-200/80 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600"
													title={`${(purchase.studies_count ?? purchase.items_count) || 0} estudios en el pedido`}
												>
													+{extraStudies} {extraStudies === 1 ? "estudio" : "estudios"}
												</span>
											)}
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-start gap-3">
											<img
												src={laboratoryLogoSrc(purchase)}
												alt=""
												className="mt-0.5 h-9 w-auto max-w-[3.25rem] shrink-0 rounded-lg object-contain"
											/>
											<div className="min-w-0 space-y-1.5">
												<Text className="text-sm font-medium text-zinc-800 dark:text-slate-200">
													{purchase.laboratory_name}
												</Text>
												{showFolio ? (
													<div className="flex items-center gap-1.5 font-mono text-sm font-semibold text-zinc-900 dark:text-white">
														<QrCodeIcon
															className="size-4 shrink-0 text-zinc-400 dark:text-slate-500"
															aria-hidden
														/>
														<span>{purchase.gda_order_id}</span>
													</div>
												) : null}
												{gdaConsecutivo ? (
													<div className="flex items-center gap-1.5 font-mono text-sm text-zinc-700 dark:text-slate-300">
														<QrCodeIcon
															className="size-4 shrink-0 text-zinc-400 dark:text-slate-500"
															aria-hidden
														/>
														<span>{gdaConsecutivo}</span>
													</div>
												) : null}
												{!showFolio && !gdaConsecutivo ? (
													<Text className="text-sm text-zinc-400 dark:text-slate-500">—</Text>
												) : null}
											</div>
										</div>
									</TableCell>
									<TableCell className="whitespace-nowrap text-sm text-zinc-600 dark:text-slate-400">
										{purchase.purchased_at_formatted}
									</TableCell>
									<TableCell>
										<span
											className={clsx(
												"inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold",
												badge.className,
											)}
										>
											{badge.label}
										</span>
									</TableCell>
									<TableCell>
										<InvoiceCell purchase={purchase} />
									</TableCell>
									<TableCell className="text-right">
										<div className="flex flex-col items-end gap-1.5">
											<Text className="font-semibold text-zinc-900 dark:text-white">{purchase.formatted_total}</Text>
											<PaymentMethodDisplayIcon
												method={purchase.payment_method}
												label={purchase.payment_method_label}
												size="md"
											/>
										</div>
									</TableCell>
									<TableCell className="text-right">
										<div className="relative z-10 flex justify-end">
											<OrderRowActions purchase={purchase} requireOtpThen={requireOtpThen} layout="row" />
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
