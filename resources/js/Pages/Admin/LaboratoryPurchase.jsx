import React, { useState } from "react";
import axios from "axios";
import { useForm, Link } from "@inertiajs/react";

import {
	DocumentTextIcon,
	QrCodeIcon,
	EllipsisHorizontalIcon,
} from "@heroicons/react/16/solid";

import {
	TrashIcon,
	CalendarDaysIcon,
	EnvelopeIcon,
	ExclamationTriangleIcon,
	ArrowPathIcon,
	DocumentDuplicateIcon,
} from "@heroicons/react/24/outline";

import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";

import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";

import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import PhoneButton from "@/Components/PhoneButton";
import CustomerLink from "@/Components/CustomerLink";
import InvoiceDialog from "@/Components/InvoiceDialog";
import ResultsDialog from "@/Components/ResultsDialog";
import DevAssistanceButton from "@/Components/DevAssistance/DevAssistanceButton";
import DevAssistanceDropdown from "@/Components/DevAssistance/DevAssistanceDropdown";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import PaymentDetails from "@/Components/PaymentDetails";
import CouponReversalNotice from "@/Components/Admin/CouponReversalNotice";
import RecoverGdaModal from "@/Components/Admin/RecoverGdaModal";
import ReplaceGdaModal from "@/Components/Admin/ReplaceGdaModal";
import { buildLaboratoryPurchaseTotals } from "@/lib/laboratoryPurchaseTotals";

function normalizePackageFeatureLabels(raw) {
	if (raw == null) return [];
	let list = [];
	if (Array.isArray(raw)) {
		list = raw;
	} else if (typeof raw === "string") {
		try {
			const parsed = JSON.parse(raw);
			list = Array.isArray(parsed) ? parsed : [];
		} catch {
			return [];
		}
	} else {
		return [];
	}
	return list
		.map((entry) => {
			if (typeof entry === "string") return entry.trim();
			if (entry != null && typeof entry === "object" && "name" in entry) {
				return String(entry.name ?? "").trim();
			}
			return String(entry ?? "").trim();
		})
		.filter(Boolean);
}

const GDA_STATUS_BADGES = {
	legacy: { label: "GDA histórico", color: "zinc" },
	pending: { label: "GDA pendiente", color: "slate" },
	confirmed: { label: "GDA confirmado", color: "emerald" },
	failed: { label: "GDA fallido", color: "red" },
	uncertain: { label: "GDA por validar", color: "amber" },
};

function GdaStatusBadge({ status }) {
	const badge = GDA_STATUS_BADGES[status] ?? GDA_STATUS_BADGES.legacy;

	return <Badge color={badge.color}>{badge.label}</Badge>;
}

function SummaryCard({ title, children, className = "" }) {
	return (
		<section
			className={`rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 ${className}`}
		>
			<Subheading>{title}</Subheading>
			<div className="mt-4 space-y-3">{children}</div>
		</section>
	);
}

function SummaryField({ label, children }) {
	return (
		<div className="grid gap-1 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
			<div className="text-zinc-500 dark:text-slate-400">{label}</div>
			<div className="min-w-0 font-medium text-zinc-950 dark:text-white">
				{children}
			</div>
		</div>
	);
}


export default function LaboratoryPurchase({
	laboratoryPurchase,
	isCancelled = false,
	couponReversal = null,
	showDeleteButton,
	canResendConfirmationEmail,
	canUploadInvoice = false,
	canRecoverGda = false,
	gdaRecoverPreview = null,
	canReplaceGda = false,
	gdaReplacePreview = null,
	hasSampleCollected,
	hasResultsAvailable,
	hasManualResults = false,
	latestSampleCollectionAt,
	latestResultsAt,
}) {

	return (
		<AdminLayout title="Pedido de laboratorio">

			<Header
				laboratoryPurchase={laboratoryPurchase}
				isCancelled={isCancelled}
				showDeleteButton={showDeleteButton}
				canResendConfirmationEmail={canResendConfirmationEmail}
				canUploadInvoice={canUploadInvoice}
				canRecoverGda={canRecoverGda}
				gdaRecoverPreview={gdaRecoverPreview}
				canReplaceGda={canReplaceGda}
				gdaReplacePreview={gdaReplacePreview}
				hasSampleCollected={hasSampleCollected}
				hasResultsAvailable={hasResultsAvailable}
				hasManualResults={hasManualResults}
				latestSampleCollectionAt={latestSampleCollectionAt}
				latestResultsAt={latestResultsAt}
			/>

			<div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,28rem)]">
				<div className="grid gap-4 lg:grid-cols-2">
					<Patient laboratoryPurchase={laboratoryPurchase} />
					<Order laboratoryPurchase={laboratoryPurchase} />
					<LaboratoryAppointment laboratoryPurchase={laboratoryPurchase} />
				</div>

				<div className="space-y-6">
					{laboratoryPurchase.transactions.length > 0 && (
						<PaymentDetails
							transaction={laboratoryPurchase.transactions[0]}
							purchase={laboratoryPurchase}
						/>
					)}

					<CouponReversalNotice couponReversal={couponReversal} />
				</div>
			</div>

		</AdminLayout>
	);
}


function Header({
	laboratoryPurchase,
	isCancelled = false,
	showDeleteButton,
	canResendConfirmationEmail,
	canUploadInvoice = false,
	canRecoverGda = false,
	gdaRecoverPreview = null,
	canReplaceGda = false,
	gdaReplacePreview = null,
	hasSampleCollected,
	hasResultsAvailable,
	hasManualResults,
	latestSampleCollectionAt,
	latestResultsAt,
}) {

	const resendForm = useForm({});
	const [recoverGdaOpen, setRecoverGdaOpen] = useState(false);
	const [replaceGdaOpen, setReplaceGdaOpen] = useState(false);

	const [loadingResults, setLoadingResults] = useState(false);

	const [debugRequest, setDebugRequest] = useState(null);
	const [debugResponse, setDebugResponse] = useState(null);
	const [debugError, setDebugError] = useState(null);

	const isLocal = import.meta.env.VITE_APP_ENV === "local";

	const fetchResults = async () => {

		const endpoint = route(
			"admin.laboratory-purchases.fetch-results",
			laboratoryPurchase.id
		);

		const payloadDebug = {
			purchase_id: laboratoryPurchase.id,
			gda_consecutivo: laboratoryPurchase.gda_consecutivo,
			gda_order_id: laboratoryPurchase.gda_order_id
		};

		setDebugRequest({
			endpoint,
			payload: payloadDebug
		});

		console.log("REQUEST", payloadDebug);

		try {

			setLoadingResults(true);

			const response = await axios.post(endpoint);

			console.log("RESPONSE", response.data);

			setDebugResponse(response.data);

			const base64 = response.data.pdf_base64;

			if (!base64) {
				alert("No se recibió PDF");
				return;
			}

			const pdfWindow = window.open("");

			pdfWindow.document.write(
				`<iframe width="100%" height="100%" src="data:application/pdf;base64,${base64}"></iframe>`
			);

		} catch (error) {

			console.error("ERROR", error);

			setDebugError(error.response?.data || error.message);

			alert("Error obteniendo resultados del laboratorio");

		} finally {

			setLoadingResults(false);

		}
	};

	return (
		<>

			<LaboratoryBrandCard
				className="w-28"
				src={"/images/gda/GDA-" + laboratoryPurchase.brand.toUpperCase() + ".png"}
			/>

			<div className="flex w-full flex-wrap justify-between gap-4">

				<div className="space-y-4">

					<Text className="flex items-center gap-2 !text-xs">
						<CalendarDaysIcon className="size-5 text-gray-500" />
						{laboratoryPurchase.formatted_created_at}
					</Text>

					<div className="flex flex-wrap items-center gap-4">

						<Heading>Pedido de laboratorio</Heading>

						<GdaStatusBadge status={laboratoryPurchase.gda_status} />

						<Badge color="sky">
							<QrCodeIcon className="size-5" />
							<span className="text-lg">
								{laboratoryPurchase.gda_order_id}
							</span>
						</Badge>

						{/* New badge for gda_consecutivo */}
						{laboratoryPurchase.gda_consecutivo && (
							<Badge color="purple">
								<span className="text-lg">
									{laboratoryPurchase.gda_consecutivo}
								</span>
							</Badge>
						)}

						<Badge color={hasSampleCollected ? "amber" : "slate"}>
							{hasSampleCollected ? "Muestra tomada" : "Pendiente toma"}

							{hasSampleCollected && latestSampleCollectionAt && (
								<span className="ml-2 text-xs opacity-70">
									{latestSampleCollectionAt}
								</span>
							)}
						</Badge>

						<div className="flex flex-wrap items-center gap-2">
							<Badge color={hasResultsAvailable ? "emerald" : "slate"}>
								{hasResultsAvailable ? "Resultados disponibles" : "Resultados pendientes"}

								{hasResultsAvailable && latestResultsAt && (
									<span className="ml-2 text-xs opacity-70">
										{latestResultsAt}
									</span>
								)}
							</Badge>
							{hasManualResults && (
								<Badge color="violet" className="font-medium">
									PDF · carga manual
								</Badge>
							)}
						</div>

					</div>

				</div>

				{showDeleteButton && (
					<DeleteDialog
						laboratoryPurchase={laboratoryPurchase}
						className="w-full self-end sm:w-auto"
					/>
				)}

			</div>

			{isCancelled && (
				<div
					role="alert"
					className="flex w-full items-start gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-900 shadow-sm dark:border-red-800 dark:bg-red-950/40 dark:text-red-100"
				>
					<ExclamationTriangleIcon className="mt-0.5 size-6 shrink-0 text-red-600 dark:text-red-400" />
					<div>
						<p className="font-semibold">Pedido cancelado</p>
						<p className="text-sm text-red-700 dark:text-red-300">
							Este pedido fue cancelado. Algunas operaciones administrativas ya no aplican.
						</p>
					</div>
				</div>
			)}

			{laboratoryPurchase.replacement_laboratory_purchase && (
				<div className="flex w-full items-start gap-3 rounded-lg border border-violet-300 bg-violet-50 px-4 py-3 text-violet-950 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-100">
					<DocumentDuplicateIcon className="mt-0.5 size-6 shrink-0" />
					<div className="text-sm">
						<p className="font-semibold">Pedido reemplazado</p>
						<p>
							La orden operativa en GDA continúa en el pedido{" "}
							<Link
								href={route(
									"admin.laboratory-purchases.show",
									laboratoryPurchase.replacement_laboratory_purchase.id,
								)}
								className="font-medium underline"
							>
								#{laboratoryPurchase.replacement_laboratory_purchase.id}
							</Link>
							.
						</p>
					</div>
				</div>
			)}

			{laboratoryPurchase.replaced_laboratory_purchase && (
				<div className="flex w-full items-start gap-3 rounded-lg border border-violet-300 bg-violet-50 px-4 py-3 text-violet-950 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-100">
					<DocumentDuplicateIcon className="mt-0.5 size-6 shrink-0" />
					<div className="text-sm">
						<p className="font-semibold">Pedido de reemplazo</p>
						<p>
							Reemplaza al pedido original{" "}
							<Link
								href={route(
									"admin.laboratory-purchases.show",
									laboratoryPurchase.replaced_laboratory_purchase.id,
								)}
								className="font-medium underline"
							>
								#{laboratoryPurchase.replaced_laboratory_purchase.id}
							</Link>
							. El cobro original permanece en el pedido anterior.
						</p>
					</div>
				</div>
			)}

			{(laboratoryPurchase.has_gda_warning ||
				laboratoryPurchase.gda_status === "uncertain") &&
				!laboratoryPurchase.replacement_laboratory_purchase && (
				<div
					role="alert"
					className="flex w-full items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 shadow-sm dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
				>
					<ExclamationTriangleIcon className="mt-0.5 size-6 shrink-0 text-amber-600 dark:text-amber-400" />
					<div className="space-y-1 text-sm">
						<p className="font-semibold">GDA no confirmó la orden</p>
						{laboratoryPurchase.gda_warning_message && (
							<p>{laboratoryPurchase.gda_warning_message}</p>
						)}
						{laboratoryPurchase.gda_description &&
							laboratoryPurchase.gda_description !==
								laboratoryPurchase.gda_warning_message && (
								<p>
									<strong>Detalle GDA:</strong>{" "}
									{laboratoryPurchase.gda_description}
								</p>
							)}
						{(laboratoryPurchase.gda_mensaje ||
							laboratoryPurchase.gda_code_http) && (
							<p className="text-amber-800 dark:text-amber-200">
								{laboratoryPurchase.gda_mensaje &&
									`mensaje: ${laboratoryPurchase.gda_mensaje}`}
								{laboratoryPurchase.gda_code_http != null &&
									` · codeHttp: ${laboratoryPurchase.gda_code_http}`}
							</p>
						)}
					</div>
				</div>
			)}

			<div className="flex w-full flex-wrap items-start gap-4">

				<CustomerLink
					href={route(
						"admin.customers.show",
						laboratoryPurchase.customer.id
					)}
				>
					{laboratoryPurchase.customer.user.full_name}
				</CustomerLink>

				{canReplaceGda && gdaReplacePreview && (
					<>
						<Button
							color="violet"
							type="button"
							onClick={() => setReplaceGdaOpen(true)}
						>
							<DocumentDuplicateIcon className="size-5" />
							Crear pedido de reemplazo
						</Button>
						<ReplaceGdaModal
							open={replaceGdaOpen}
							onClose={() => setReplaceGdaOpen(false)}
							laboratoryPurchase={laboratoryPurchase}
							preview={gdaReplacePreview}
						/>
					</>
				)}

				{canRecoverGda && gdaRecoverPreview && (
					<>
						<Button
							outline
							type="button"
							onClick={() => setRecoverGdaOpen(true)}
						>
							<ArrowPathIcon className="size-5" />
							Recuperar mismo pedido
						</Button>
						<RecoverGdaModal
							open={recoverGdaOpen}
							onClose={() => setRecoverGdaOpen(false)}
							laboratoryPurchase={laboratoryPurchase}
							preview={gdaRecoverPreview}
						/>
					</>
				)}

				{canResendConfirmationEmail && (
					<div className="flex flex-col gap-1">
						<Button
							outline
							type="button"
							onClick={() =>
								resendForm.post(
									route(
										"admin.laboratory-purchases.resend-confirmation-email",
										{
											laboratory_purchase:
												laboratoryPurchase.id,
										}
									),
									{ preserveScroll: true }
								)
							}
							disabled={resendForm.processing}
						>
							<EnvelopeIcon className="size-5" />
							{resendForm.processing
								? "Enviando correo…"
								: "Reenviar correo de compra"}
						</Button>
						{resendForm.errors.resend_confirmation && (
							<Text className="!text-sm text-red-600">
								{resendForm.errors.resend_confirmation}
							</Text>
						)}
					</div>
				)}

				{canUploadInvoice && (
					<InvoiceDialog
						storeRoute={route("admin.laboratory-purchases.invoice", {
							laboratory_purchase: laboratoryPurchase.id,
						})}
						invoiceRoute={
							laboratoryPurchase.invoice
								? route("invoice", {
									invoice: laboratoryPurchase.invoice.id,
								})
								: null
						}
						invoiceXmlRoute={
							laboratoryPurchase.invoice?.invoice_xml
								? route("invoice.xml", {
									invoice: laboratoryPurchase.invoice.id,
								})
								: null
						}
						invoiceRequest={laboratoryPurchase.invoice_request}
						hasInvoice={!!laboratoryPurchase.invoice}
					/>
				)}

				<ResultsDialog
					storeRoute={route("admin.laboratory-purchases.results", {
						laboratory_purchase: laboratoryPurchase,
					})}
					resultsRoute={
						laboratoryPurchase.results
							? route("laboratory-purchases.results", {
								laboratory_purchase: laboratoryPurchase,
							})
							: null
					}
					hasResults={!!laboratoryPurchase.results}
				/>

				{/*}
				{hasResultsAvailable && (
					<Button
						color="emerald"
						onClick={fetchResults}
						disabled={loadingResults}
					>
						<DocumentTextIcon />
						{loadingResults
							? "Consultando GDA..."
							: "Consultar resultados GDA"}
					</Button>
				)}
				*/}
				{laboratoryPurchase.dev_assistance_requests.length === 0 ? (
					<DevAssistanceButton
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							}
						)}
					/>
				) : (
					<DevAssistanceDropdown
						requests={laboratoryPurchase.dev_assistance_requests}
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							}
						)}
						resolveRouteName="admin.laboratory-purchases.dev-assistance-request.resolved"
						unresolveRouteName="admin.laboratory-purchases.dev-assistance-request.unresolved"
						routeParams={{
							laboratory_purchase: laboratoryPurchase.id,
						}}
					/>
				)}

			</div>

			{isLocal && (
				<details className="w-full rounded-lg border border-zinc-800 bg-black px-4 py-3 text-xs font-mono text-green-400">
					<summary className="cursor-pointer text-yellow-400">
						GDA DEBUG PANEL
					</summary>

					<div className="mt-4 space-y-4">
						{debugRequest && (
							<div>
								<div className="text-yellow-300">REQUEST</div>
								<pre className="overflow-auto">
									{JSON.stringify(debugRequest, null, 2)}
								</pre>
							</div>
						)}

						{debugResponse && (
							<div>
								<div className="text-blue-300">RESPONSE</div>
								<pre className="overflow-auto">
									{JSON.stringify(debugResponse, null, 2)}
								</pre>
							</div>
						)}

						{debugError && (
							<div>
								<div className="text-red-400">ERROR</div>
								<pre className="overflow-auto">
									{JSON.stringify(debugError, null, 2)}
								</pre>
							</div>
						)}
					</div>
				</details>
			)}

		</>
	);
}


function Patient({ laboratoryPurchase }) {

	return (

		<SummaryCard title="Paciente">
			<SummaryField label="Nombre">
				{laboratoryPurchase.full_name ?? "..."}
			</SummaryField>
			<SummaryField label="Sexo">
				{laboratoryPurchase.formatted_gender}
			</SummaryField>
			<SummaryField label="Nacimiento">
				{laboratoryPurchase.formatted_birth_date}
			</SummaryField>
			<SummaryField label="Teléfono">
				<PhoneButton
					phone={laboratoryPurchase.phone}
					fullPhone={laboratoryPurchase.full_phone}
					countryCode={laboratoryPurchase.phone_country}
				/>
			</SummaryField>
		</SummaryCard>

	);

}


function Order({ laboratoryPurchase }) {
	const totals = buildLaboratoryPurchaseTotals(laboratoryPurchase);

	return (

		<SummaryCard title="Pedido" className="lg:col-span-1">
			<div className="space-y-3">
				<div className="flex flex-wrap gap-2">
					{laboratoryPurchase.laboratory_purchase_items.map((item) => (
						<Badge key={item.id} color="slate">
							{item.name} ({item.formatted_price})
						</Badge>
					))}
				</div>

				{laboratoryPurchase.laboratory_purchase_items.some(
					(item) => normalizePackageFeatureLabels(item.feature_list).length > 0
				) && (
					<details className="rounded-md border border-orange-200 bg-orange-50/70 p-3 text-xs dark:border-orange-900/60 dark:bg-orange-950/30">
						<summary className="cursor-pointer font-semibold text-orange-800 dark:text-orange-200">
							Ver contenidos de paquetes
						</summary>
						<div className="mt-3 space-y-3 text-zinc-700 dark:text-slate-300">
							{laboratoryPurchase.laboratory_purchase_items.map((item) => {
								const packageFeatures = normalizePackageFeatureLabels(item.feature_list);

								if (packageFeatures.length === 0) {
									return null;
								}

								return (
									<div key={item.id}>
										<p className="font-semibold">{item.name}</p>
										<ul className="mt-1 list-disc space-y-0.5 pl-4">
											{packageFeatures.map((label, idx) => (
												<li key={`${item.id}-f-${idx}`}>{label}</li>
											))}
										</ul>
									</div>
								);
							})}
						</div>
					</details>
				)}
			</div>

			<div className="grid gap-2 border-t border-zinc-950/5 pt-3 text-sm dark:border-white/5">
				<div className="flex justify-between gap-4">
					<span className="text-zinc-500 dark:text-slate-400">Subtotal</span>
					<span className="font-medium tabular-nums">{totals.subtotal}</span>
				</div>
				{laboratoryPurchase.coupon_discount_cents > 0 && (
					<div className="flex justify-between gap-4 text-violet-800 dark:text-violet-200">
						<span>Crédito a favor</span>
						<span className="font-medium tabular-nums">
							−{laboratoryPurchase.formatted_coupon_discount}
						</span>
					</div>
				)}
				<div className="flex justify-between gap-4 text-base">
					<span className="font-semibold">Total pagado</span>
					<Strong className="tabular-nums">
						{laboratoryPurchase.formatted_net_total ?? totals.netTotal}
					</Strong>
				</div>
			</div>
		</SummaryCard>

	);

}


function LaboratoryAppointment({ laboratoryPurchase }) {

	if (!laboratoryPurchase.laboratory_appointment) {
		return null;
	}

	return (

		<SummaryCard title="Confirmación de cita">
			<SummaryField label="Fecha">
				{laboratoryPurchase.laboratory_appointment.formatted_appointment_date ?? "..."}
			</SummaryField>
			<SummaryField label="Sucursal">
				{laboratoryPurchase.laboratory_appointment.laboratory_store?.name ?? "..."}
			</SummaryField>
		</SummaryCard>

	);

}


function DeleteDialog({ laboratoryPurchase, className = "" }) {

	const [isOpen, setIsOpen] = useState(false);

	const { delete: destroy, processing } = useForm({});

	const handleDelete = () => {

		if (!processing) {

			destroy(
				route("admin.laboratory-purchases.destroy", {
					laboratory_purchase: laboratoryPurchase,
				}),
				{ preserveScroll: true }
			);

		}

	};

	return (

		<>

			<Dropdown>

				<DropdownButton outline className={className}>

					Acciones

					<EllipsisHorizontalIcon />

				</DropdownButton>

				<DropdownMenu>

					<DropdownItem onClick={() => setIsOpen(true)}>

						<TrashIcon className="stroke-red-500 dark:stroke-red-400" />

						Cancelar pedido

					</DropdownItem>

				</DropdownMenu>

			</Dropdown>

			<DeleteConfirmationModal
				isOpen={isOpen}
				close={() => setIsOpen(false)}
				title="Cancelar pedido"
				description="¿Estás seguro de cancelar este pedido?"
				processing={processing}
				destroy={handleDelete}
			/>

		</>

	);

}
