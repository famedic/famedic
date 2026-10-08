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
	BeakerIcon,
	CheckCircleIcon,
	ClipboardDocumentListIcon,
	CreditCardIcon,
	DocumentArrowDownIcon,
	UserCircleIcon,
	MapPinIcon,
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

function SummaryCard({ title, children, className = "", actions = null }) {
	return (
		<section
			className={`rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 ${className}`}
		>
			<div className="flex items-start justify-between gap-3">
				<Subheading className="text-famedic-darker">{title}</Subheading>
				{actions}
			</div>
			<div className="mt-4 space-y-3">{children}</div>
		</section>
	);
}

function SummaryField({ label, children }) {
	return (
		<div className="grid gap-1 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
			<div className="text-slate-500 dark:text-slate-400">{label}</div>
			<div className="min-w-0 font-semibold text-famedic-darker dark:text-white">
				{children}
			</div>
		</div>
	);
}

function getInitials(name, fallback = "NA") {
	const initials = String(name ?? "")
		.trim()
		.split(/\s+/)
		.slice(0, 2)
		.map((part) => part[0])
		.join("")
		.toUpperCase();

	return initials || fallback;
}

function SoftIcon({ icon: Icon, color = "violet", className = "" }) {
	const colors = {
		emerald: "bg-emerald-50 text-emerald-600",
		violet: "bg-violet-50 text-violet-600",
		amber: "bg-amber-50 text-amber-600",
		blue: "bg-blue-50 text-blue-600",
		rose: "bg-rose-50 text-rose-600",
		slate: "bg-slate-100 text-slate-500",
	};

	return (
		<span
			className={`inline-flex size-11 shrink-0 items-center justify-center rounded-full ${colors[color]} ${className}`}
		>
			<Icon className="size-5" />
		</span>
	);
}

function formatDateTime(value) {
	if (!value) return "—";
	return new Date(value).toLocaleString("es-MX");
}

function maskStoragePath(path) {
	if (!path) return "—";
	if (path.length <= 34) return path;
	return `${path.substring(0, 14)}…${path.substring(path.length - 18)}`;
}

function parseDateValue(value) {
	if (!value) return null;
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? null : date;
}

function daysSince(value) {
	const date = parseDateValue(value);
	if (!date) return null;
	const diff = Date.now() - date.getTime();
	return Math.max(0, Math.floor(diff / 86_400_000));
}

function formatDaysLabel(days) {
	if (days == null) return "Sin fecha base";
	if (days === 0) return "Hoy";
	if (days === 1) return "1 día";
	return `${days} días`;
}

function resultPdfBadge(pdf) {
	if (!pdf) return { color: "slate", label: "Sin PDF" };

	switch (pdf.location) {
		case "storage":
			return { color: "emerald", label: pdf.label };
		case "storage_stale":
		case "db_base64_stale":
			return { color: "amber", label: pdf.label };
		case "db_base64":
			return { color: "violet", label: pdf.label };
		case "gda_provider":
			return { color: "sky", label: pdf.label };
		default:
			return { color: "slate", label: pdf.label ?? "Sin PDF" };
	}
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

function SectionNav({ activeTab, onChange }) {
	const items = [
		{ key: "summary", label: "Resumen", icon: ClipboardDocumentListIcon },
		{ key: "studies", label: "Estudios", icon: BeakerIcon },
		{
			key: "appointment",
			label: "Cita",
			icon: CalendarDaysIcon,
		},
		{
			key: "sample_collection",
			label: "Muestra",
			icon: CalendarDaysIcon,
		},
		{ key: "results", label: "Resultados", icon: DocumentTextIcon },
		{ key: "payment", label: "Facturacion", icon: CreditCardIcon },
		{ key: "documents", label: "Docs", icon: DocumentDuplicateIcon },
		{ key: "history", label: "Historial", icon: ArrowPathIcon },
	];

	return (
		<nav className="overflow-x-auto rounded-xl border border-slate-200 bg-white px-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
			<div className="flex min-w-max items-center gap-2">
				{items.map((item) => (
					<button
						key={item.key}
						type="button"
						onClick={() => onChange(item.key)}
						className={`inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-semibold transition ${
							activeTab === item.key
								? "border-violet-500 text-violet-700"
								: "border-transparent text-slate-600 hover:text-famedic-darker"
						}`}
					>
						<item.icon className="size-4" />
						{item.label}
					</button>
				))}
			</div>
		</nav>
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
	sampleCollectionNotifications = [],
	resultsGdaSummary = null,
	invoiceRequestWorkflow = null,
	fiscalCertificateAvailability = null,
}) {
	const [activeTab, setActiveTab] = useState("summary");
	const paymentCard = (
		<PaymentBillingPanel
			laboratoryPurchase={laboratoryPurchase}
			canUploadInvoice={canUploadInvoice}
			invoiceRequestWorkflow={invoiceRequestWorkflow}
			fiscalCertificateAvailability={fiscalCertificateAvailability}
		/>
	);
	const appointmentCard = (
		<AppointmentPanel laboratoryPurchase={laboratoryPurchase} />
	);
	const sampleCollectionCard = (
		<SampleCollectionPanel
			laboratoryPurchase={laboratoryPurchase}
			sampleCollectionNotifications={sampleCollectionNotifications}
		/>
	);

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

			<SectionNav activeTab={activeTab} onChange={setActiveTab} />

			{activeTab === "summary" && (
				<div className="space-y-4">
					<Patient laboratoryPurchase={laboratoryPurchase} />
					<Order laboratoryPurchase={laboratoryPurchase} />
					{laboratoryPurchase.laboratory_appointment && (
						<AppointmentPanel
							laboratoryPurchase={laboratoryPurchase}
						/>
					)}
					{paymentCard}
					<DocumentsPanel laboratoryPurchase={laboratoryPurchase} />
					<CouponReversalNotice couponReversal={couponReversal} />
				</div>
			)}

			{activeTab !== "summary" && (
				<div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,0.8fr)]">
					<div className="space-y-4">
						{activeTab === "studies" && (
							<Order laboratoryPurchase={laboratoryPurchase} />
						)}
						{activeTab === "appointment" && appointmentCard}
						{activeTab === "sample_collection" &&
							sampleCollectionCard}
						{activeTab === "results" && (
							<ResultsPanel
								laboratoryPurchase={laboratoryPurchase}
								hasResultsAvailable={hasResultsAvailable}
								hasManualResults={hasManualResults}
								latestResultsAt={latestResultsAt}
								resultsGdaSummary={resultsGdaSummary}
							/>
						)}
						{activeTab === "payment" && (
							<>
								{paymentCard}
								<CouponReversalNotice
									couponReversal={couponReversal}
								/>
							</>
						)}
						{activeTab === "documents" && (
							<DocumentsPanel
								laboratoryPurchase={laboratoryPurchase}
							/>
						)}
						{activeTab === "history" && (
							<HistoryPanel
								laboratoryPurchase={laboratoryPurchase}
								hasSampleCollected={hasSampleCollected}
								hasResultsAvailable={hasResultsAvailable}
								latestSampleCollectionAt={
									latestSampleCollectionAt
								}
								latestResultsAt={latestResultsAt}
							/>
						)}
					</div>
					<div className="space-y-4">
						<Patient
							laboratoryPurchase={laboratoryPurchase}
							compact
						/>
					</div>
				</div>
			)}
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
	const hasAppointment = Boolean(laboratoryPurchase.laboratory_appointment);
	const hasPayment = laboratoryPurchase.transactions.length > 0;

	const fetchResults = async () => {
		const endpoint = route(
			"admin.laboratory-purchases.fetch-results",
			laboratoryPurchase.id,
		);

		const payloadDebug = {
			purchase_id: laboratoryPurchase.id,
			gda_consecutivo: laboratoryPurchase.gda_consecutivo,
			gda_order_id: laboratoryPurchase.gda_order_id,
		};

		setDebugRequest({
			endpoint,
			payload: payloadDebug,
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
				`<iframe width="100%" height="100%" src="data:application/pdf;base64,${base64}"></iframe>`,
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
			<div className="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
				<div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
					<div className="min-w-0 space-y-3">
						<div className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
							<Link
								href={route("admin.laboratory-purchases.index")}
								className="font-medium text-slate-500 hover:text-violet-700"
							>
								Laboratorios
							</Link>
							<span>/</span>
							<span>Pedidos</span>
							<span>/</span>
							<span className="font-semibold text-famedic-darker">
								#{laboratoryPurchase.id}
							</span>
						</div>

						<div className="flex flex-wrap items-center gap-3">
							<SoftIcon icon={DocumentTextIcon} color="violet" />
							<Heading className="text-famedic-darker">
								Pedido de laboratorio
							</Heading>
							<Badge color="purple">
								#{laboratoryPurchase.id}
							</Badge>
							<GdaStatusBadge
								status={laboratoryPurchase.gda_status}
							/>
							{laboratoryPurchase.gda_order_id && (
								<Badge color="sky">
									<QrCodeIcon className="size-4" />
									{laboratoryPurchase.gda_order_id}
								</Badge>
							)}
							{laboratoryPurchase.gda_consecutivo && (
								<Badge color="purple">
									{laboratoryPurchase.gda_consecutivo}
								</Badge>
							)}
							{hasManualResults && (
								<Badge color="violet">PDF manual</Badge>
							)}
						</div>

						<div className="flex flex-wrap items-center gap-5 text-sm text-slate-600">
							<CustomerLink
								href={route(
									"admin.customers.show",
									laboratoryPurchase.customer.id,
								)}
							>
								{laboratoryPurchase.customer.user.full_name}
							</CustomerLink>
						</div>
					</div>

					<div className="flex flex-wrap items-center gap-2">
						<LaboratoryBrandCard
							className="w-28"
							src={
								"/images/gda/GDA-" +
								laboratoryPurchase.brand.toUpperCase() +
								".png"
							}
						/>
						<ActionsMenu
							laboratoryPurchase={laboratoryPurchase}
							showDeleteButton={showDeleteButton}
							canResendConfirmationEmail={
								canResendConfirmationEmail
							}
							resendForm={resendForm}
							canUploadInvoice={canUploadInvoice}
							canRecoverGda={canRecoverGda}
							gdaRecoverPreview={gdaRecoverPreview}
							recoverGdaOpen={recoverGdaOpen}
							setRecoverGdaOpen={setRecoverGdaOpen}
							canReplaceGda={canReplaceGda}
							gdaReplacePreview={gdaReplacePreview}
							replaceGdaOpen={replaceGdaOpen}
							setReplaceGdaOpen={setReplaceGdaOpen}
						/>
					</div>
				</div>

				<div className="p-5">
					<ProgressPanel
						hasPayment={hasPayment}
						hasAppointment={hasAppointment}
						hasSampleCollected={hasSampleCollected}
						hasResultsAvailable={hasResultsAvailable}
						invoiceRequest={laboratoryPurchase.invoice_request}
						hasInvoice={!!laboratoryPurchase.invoice}
						createdAt={laboratoryPurchase.formatted_created_at}
						appointmentAt={
							laboratoryPurchase.laboratory_appointment
								?.formatted_appointment_date
						}
						latestSampleCollectionAt={latestSampleCollectionAt}
						latestResultsAt={latestResultsAt}
					/>
				</div>
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
							Este pedido fue cancelado. Algunas operaciones
							administrativas ya no aplican.
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
									laboratoryPurchase
										.replacement_laboratory_purchase.id,
								)}
								className="font-medium underline"
							>
								#
								{
									laboratoryPurchase
										.replacement_laboratory_purchase.id
								}
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
									laboratoryPurchase
										.replaced_laboratory_purchase.id,
								)}
								className="font-medium underline"
							>
								#
								{
									laboratoryPurchase
										.replaced_laboratory_purchase.id
								}
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
							<p className="font-semibold">
								GDA no confirmó la orden
							</p>
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

			{isLocal && (
				<details className="w-full rounded-lg border border-zinc-800 bg-black px-4 py-3 font-mono text-xs text-green-400">
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

function ProgressPanel({
	hasPayment,
	hasAppointment,
	hasSampleCollected,
	hasResultsAvailable,
	invoiceRequest,
	hasInvoice = false,
	createdAt,
	appointmentAt,
	latestSampleCollectionAt,
	latestResultsAt,
}) {
	const hasPendingInvoice = Boolean(invoiceRequest && !hasInvoice);
	const isCompleted = hasResultsAvailable && !hasPendingInvoice;

	const steps = [
		{
			label: "Compra realizada",
			detail: createdAt,
			done: hasPayment,
			icon: CheckCircleIcon,
		},
		invoiceRequest && {
			label: "Solicitud de factura",
			detail: invoiceRequest.formatted_created_at ?? "Solicitada",
			done: true,
			icon: DocumentDuplicateIcon,
		},
		{
			label: "Toma de muestra",
			detail: hasSampleCollected
				? (latestSampleCollectionAt ?? "Muestra tomada")
				: (appointmentAt ?? "Pendiente programar"),
			done: hasSampleCollected,
			current: !hasSampleCollected,
			icon: CalendarDaysIcon,
		},
		{
			label: "En laboratorio",
			detail: hasSampleCollected ? "En proceso" : "Sin iniciar",
			done: hasSampleCollected,
			icon: BeakerIcon,
		},
		{
			label: "Resultados",
			detail: hasResultsAvailable
				? (latestResultsAt ?? "Disponibles")
				: "Pendiente",
			done: hasResultsAvailable,
			icon: DocumentTextIcon,
		},
		{
			label:
				hasPendingInvoice && hasResultsAvailable
					? "Factura pendiente"
					: "Completado",
			detail: isCompleted
				? "Listo"
				: hasPendingInvoice
					? "Pendiente de carga"
					: "Pendiente",
			done: isCompleted,
			current: hasPendingInvoice && hasResultsAvailable,
			icon:
				hasPendingInvoice && hasResultsAvailable
					? DocumentTextIcon
					: CheckCircleIcon,
		},
	].filter(Boolean);

	return (
		<div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950/40">
			<div
				className={`grid gap-4 ${
					invoiceRequest ? "md:grid-cols-6" : "md:grid-cols-5"
				}`}
			>
				{steps.map((step, index) => (
					<div key={step.label} className="relative">
						{index < steps.length - 1 && (
							<div
								className={`absolute left-12 top-5 hidden h-0.5 w-[calc(100%-2rem)] md:block ${
									steps[index + 1].done || step.done
										? "bg-violet-400"
										: "bg-slate-200"
								}`}
							/>
						)}
						<div className="relative z-10 flex items-start gap-3 md:block">
							<span
								className={`inline-flex size-10 items-center justify-center rounded-full ring-4 ring-white dark:ring-zinc-950 ${
									step.done
										? "bg-emerald-500 text-white"
										: step.current
											? "bg-violet-500 text-white"
											: "bg-slate-100 text-slate-400"
								}`}
							>
								<step.icon className="size-5" />
							</span>
							<div className="mt-0 min-w-0 md:mt-3">
								<p
									className={`text-sm font-semibold ${
										step.current
											? "text-violet-700"
											: "text-famedic-darker"
									}`}
								>
									{step.label}
								</p>
								<p className="mt-1 text-xs leading-5 text-slate-500">
									{step.detail}
								</p>
							</div>
						</div>
					</div>
				))}
			</div>
		</div>
	);
}

function DocumentsPanel({ laboratoryPurchase }) {
	const hasResults = Boolean(laboratoryPurchase.results);
	const hasInvoice = Boolean(laboratoryPurchase.invoice);

	const documents = [
		{
			label: "Comprobante de pago",
			detail: "PDF · generado",
			icon: DocumentArrowDownIcon,
			color: "rose",
			available: laboratoryPurchase.transactions.length > 0,
		},
		{
			label: "Orden de compra",
			detail: "PDF · generada",
			icon: ClipboardDocumentListIcon,
			color: "blue",
			available: true,
		},
		{
			label: "Resultados",
			detail: hasResults ? "PDF disponible" : "Pendientes",
			icon: DocumentTextIcon,
			color: "amber",
			available: hasResults,
		},
		{
			label: "Factura",
			detail: hasInvoice ? "XML/PDF disponible" : "No solicitada",
			icon: DocumentDuplicateIcon,
			color: "emerald",
			available: hasInvoice,
		},
	];

	return (
		<SummaryCard title="Documentos">
			<div className="space-y-3">
				{documents.map((document) => (
					<div
						key={document.label}
						className="flex items-center gap-3 rounded-lg bg-slate-50 p-3 dark:bg-zinc-950/50"
					>
						<SoftIcon
							icon={document.icon}
							color={document.color}
							className="size-9"
						/>
						<div className="min-w-0 flex-1">
							<p className="text-sm font-semibold text-famedic-darker">
								{document.label}
							</p>
							<p className="text-xs text-slate-500">
								{document.detail}
							</p>
						</div>
						<span
							className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
								document.available
									? "bg-emerald-50 text-emerald-700"
									: "bg-slate-100 text-slate-500"
							}`}
						>
							{document.available ? "Listo" : "Pendiente"}
						</span>
					</div>
				))}
			</div>
		</SummaryCard>
	);
}

function EmptyPanel({ icon: Icon, title, description }) {
	return (
		<SummaryCard title={title}>
			<div className="flex items-start gap-4 rounded-lg bg-slate-50 p-4 dark:bg-zinc-950/50">
				<SoftIcon icon={Icon} color="slate" />
				<p className="text-sm leading-6 text-slate-600">
					{description}
				</p>
			</div>
		</SummaryCard>
	);
}

function PaymentBillingPanel({
	laboratoryPurchase,
	canUploadInvoice = false,
	invoiceRequestWorkflow = null,
	fiscalCertificateAvailability = null,
}) {
	const [invoiceOpen, setInvoiceOpen] = useState(false);
	const transaction = laboratoryPurchase.transactions[0] ?? null;
	const invoiceRequest = laboratoryPurchase.invoice_request;
	const invoice = laboratoryPurchase.invoice;
	const taxProfile =
		invoiceRequest?.tax_profile ?? invoiceRequest?.taxProfile ?? null;
	const hasInvoiceRequest = Boolean(invoiceRequest);
	const hasInvoice = Boolean(invoice);

	return (
		<SummaryCard
			title="Pago y facturacion"
			actions={
				<BillingActionsMenu
					laboratoryPurchase={laboratoryPurchase}
					canUploadInvoice={canUploadInvoice}
					onInvoiceAction={() => setInvoiceOpen(true)}
				/>
			}
		>
			<div className="space-y-5">
				<div className="grid gap-3 sm:grid-cols-3">
					<BillingStatusCard
						icon={CreditCardIcon}
						title="Pago"
						value={transaction ? "Confirmado" : "Sin pago"}
						tone={transaction ? "emerald" : "slate"}
						detail={
							transaction?.formatted_amount ??
							laboratoryPurchase.formatted_net_total ??
							"Pendiente"
						}
					/>
					<BillingStatusCard
						icon={DocumentDuplicateIcon}
						title="Solicitud de factura"
						value={
							hasInvoiceRequest ? "Solicitada" : "No solicitada"
						}
						tone={hasInvoiceRequest ? "violet" : "slate"}
						detail={
							invoiceRequestWorkflow?.workflow_status_label ??
							invoiceRequest?.formatted_created_at ??
							"Sin solicitud registrada"
						}
					/>
					<BillingStatusCard
						icon={DocumentTextIcon}
						title="Factura"
						value={hasInvoice ? "Cargada" : "Pendiente"}
						tone={hasInvoice ? "emerald" : "amber"}
						detail={
							hasInvoice
								? "PDF/XML disponibles"
								: hasInvoiceRequest
									? "Lista para cargar"
									: "Requiere solicitud"
						}
					/>
				</div>

				{canUploadInvoice && hasInvoiceRequest && !hasInvoice && (
					<div className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950 sm:flex-row sm:items-center sm:justify-between">
						<div className="flex items-start gap-3">
							<SoftIcon
								icon={DocumentTextIcon}
								color="amber"
								className="size-10 bg-white/70"
							/>
							<div>
								<p className="font-bold">
									Factura pendiente de cargar
								</p>
								<p className="mt-1 text-sm leading-6 text-amber-800">
									La solicitud ya fue enviada a facturacion.
									Sube el PDF y XML para cerrar el flujo.
								</p>
							</div>
						</div>
						<Button onClick={() => setInvoiceOpen(true)}>
							<DocumentTextIcon />
							Subir factura
						</Button>
					</div>
				)}

				<div className="space-y-4">
					<InvoiceRequestPanel
						invoiceRequest={invoiceRequest}
						invoiceRequestWorkflow={invoiceRequestWorkflow}
						hasInvoice={hasInvoice}
					/>
					<TaxInformationPanel
						invoiceRequest={invoiceRequest}
						taxProfile={taxProfile}
						fiscalCertificateAvailability={
							fiscalCertificateAvailability
						}
					/>
				</div>

				<InvoiceDocumentsPanel
					invoice={invoice}
					invoiceRequest={invoiceRequest}
					taxProfile={taxProfile}
				/>

				<InvoiceRequestWorkflowPanel
					workflow={invoiceRequestWorkflow}
				/>

				{transaction ? (
					<div className="rounded-xl border border-slate-100 p-4">
						<PaymentDetails
							transaction={transaction}
							purchase={laboratoryPurchase}
						/>
					</div>
				) : (
					<div className="flex items-start gap-4 rounded-lg bg-slate-50 p-4 dark:bg-zinc-950/50">
						<SoftIcon icon={CreditCardIcon} color="slate" />
						<p className="text-sm leading-6 text-slate-600">
							Este pedido todavia no tiene una transaccion
							asociada.
						</p>
					</div>
				)}
			</div>

			<InvoiceDialog
				storeRoute={route("admin.laboratory-purchases.invoice", {
					laboratory_purchase: laboratoryPurchase.id,
				})}
				invoiceRoute={
					invoice ? route("invoice", { invoice: invoice.id }) : null
				}
				invoiceXmlRoute={
					invoice?.invoice_xml
						? route("invoice.xml", { invoice: invoice.id })
						: null
				}
				invoiceRequest={invoiceRequest}
				hasInvoice={!!invoice}
				open={invoiceOpen}
				onOpenChange={setInvoiceOpen}
				hideTrigger
			/>
		</SummaryCard>
	);
}

function BillingStatusCard({ icon: Icon, title, value, detail, tone }) {
	return (
		<div className="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 p-4">
			<SoftIcon icon={Icon} color={tone} className="size-10" />
			<div className="min-w-0">
				<p className="text-xs font-semibold uppercase text-slate-500">
					{title}
				</p>
				<p className="mt-1 font-bold text-famedic-darker">{value}</p>
				<p className="mt-1 text-sm text-slate-500">{detail}</p>
			</div>
		</div>
	);
}

function InvoiceRequestPanel({
	invoiceRequest,
	invoiceRequestWorkflow,
	hasInvoice = false,
}) {
	if (!invoiceRequest) {
		return (
			<div className="rounded-xl border border-slate-100 p-4">
				<Subheading>Solicitud de factura</Subheading>
				<div className="mt-4 flex items-start gap-3 rounded-lg bg-slate-50 p-4">
					<SoftIcon icon={DocumentDuplicateIcon} color="slate" />
					<Text className="text-sm text-slate-600">
						El paciente no ha solicitado factura para este pedido.
					</Text>
				</div>
			</div>
		);
	}

	const requestedAt =
		invoiceRequest.created_at ?? invoiceRequest.formatted_created_at;
	const submittedAt =
		invoiceRequest.submitted_to_billing_at ??
		invoiceRequestWorkflow?.submitted_to_billing_at ??
		invoiceRequest.formatted_submitted_to_billing_at;
	const submittedLabel =
		invoiceRequestWorkflow?.formatted_submitted_to_billing_at ??
		invoiceRequest.formatted_submitted_to_billing_at;
	const baseDelayDate = submittedAt ?? requestedAt;
	const elapsedDays = hasInvoice ? 0 : daysSince(baseDelayDate);
	const delayTone = hasInvoice
		? "emerald"
		: elapsedDays == null
			? "slate"
			: elapsedDays >= 3
				? "rose"
				: elapsedDays >= 1
					? "amber"
					: "blue";

	return (
		<div className="rounded-xl border border-slate-100 p-4">
			<div className="flex items-start justify-between gap-3">
				<Subheading>Solicitud de factura</Subheading>
				<Badge color="violet">
					{invoiceRequestWorkflow?.workflow_status_label ??
						"Solicitada"}
				</Badge>
			</div>
			<div className="mt-5 space-y-4">
				<div className="grid gap-3 md:grid-cols-3">
					<InvoiceTimelineItem
						title="Solicitada"
						detail={invoiceRequest.formatted_created_at ?? "—"}
						done
					/>
					<InvoiceTimelineItem
						title="Enviada a facturación"
						detail={submittedLabel ?? "Pendiente de enviar"}
						done={Boolean(submittedLabel)}
					/>
					<InvoiceTimelineItem
						title={
							hasInvoice ? "Factura cargada" : "Tiempo pendiente"
						}
						detail={
							hasInvoice
								? "La factura ya fue cargada."
								: `${formatDaysLabel(elapsedDays)} sin factura cargada`
						}
						done={hasInvoice}
						tone={delayTone}
						isLast
					/>
				</div>
				{invoiceRequestWorkflow?.activated_by_label && (
					<div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
						<span className="font-semibold">Activación:</span>{" "}
						{invoiceRequestWorkflow.activated_by_label}
					</div>
				)}
			</div>
		</div>
	);
}

function InvoiceTimelineItem({
	title,
	detail,
	done = false,
	tone = "emerald",
	isLast = false,
}) {
	const toneClass = {
		emerald: "bg-emerald-500 text-white",
		blue: "bg-blue-500 text-white",
		amber: "bg-amber-400 text-amber-950",
		rose: "bg-rose-500 text-white",
		slate: "bg-slate-200 text-slate-500",
	}[done ? "emerald" : tone];

	return (
		<div className="relative flex gap-3 md:block">
			{!isLast && (
				<>
					<div className="absolute left-4 top-8 h-[calc(100%-0.5rem)] w-px bg-slate-200 md:hidden" />
					<div className="absolute left-8 right-[-1.5rem] top-4 hidden h-px bg-slate-200 md:block" />
				</>
			)}
			<span
				className={`relative z-10 mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full md:mt-0 ${toneClass}`}
			>
				<CheckCircleIcon className="size-4" />
			</span>
			<div className="min-w-0 rounded-lg border border-slate-100 bg-white px-3 py-2 text-sm shadow-sm md:mt-3">
				<p className="font-semibold text-famedic-darker">{title}</p>
				<p className="mt-1 text-slate-500">{detail}</p>
			</div>
		</div>
	);
}

function TaxInformationPanel({
	invoiceRequest,
	taxProfile,
	fiscalCertificateAvailability,
}) {
	if (!invoiceRequest) {
		return (
			<div className="rounded-xl border border-slate-100 p-4">
				<Subheading>Informacion fiscal</Subheading>
				<Text className="mt-4 text-sm text-slate-500">
					Sin datos fiscales enviados para este pedido.
				</Text>
			</div>
		);
	}

	const requestCertificateRoute = invoiceRequest?.fiscal_certificate
		? route("invoice-requests.fiscal-certificate", {
				invoice_request: invoiceRequest,
			})
		: null;
	const taxProfileCertificateRoute = taxProfile?.fiscal_certificate
		? route("admin.tax-profiles.fiscal-certificate", {
				tax_profile: taxProfile.id,
			})
		: null;
	const certificateRoute =
		requestCertificateRoute ?? taxProfileCertificateRoute;
	const certificateAvailability = requestCertificateRoute
		? fiscalCertificateAvailability?.invoice_request
		: taxProfileCertificateRoute
			? fiscalCertificateAvailability?.tax_profile
			: null;
	const certificateExists = certificateAvailability?.exists === true;
	const certificateHasPath = certificateAvailability?.has_path === true;

	return (
		<div className="rounded-xl border border-slate-100 p-4">
			<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<Subheading>Informacion fiscal enviada</Subheading>
					<div className="mt-2 flex flex-wrap gap-2">
						{taxProfile?.id && (
							<Badge color="emerald">Perfil vinculado</Badge>
						)}
						<FiscalCertificateAvailabilityBadge
							exists={certificateExists}
							hasPath={certificateHasPath}
						/>
					</div>
				</div>
				{certificateRoute && certificateExists && (
					<Button href={certificateRoute} target="_blank" outline>
						<DocumentTextIcon />
						Ver constancia fiscal
					</Button>
				)}
			</div>
			<div className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
				<FiscalInfoItem label="Razon social" className="sm:col-span-2">
					{invoiceRequest.name ?? taxProfile?.name ?? "—"}
				</FiscalInfoItem>
				<FiscalInfoItem label="RFC">
					{invoiceRequest.rfc ?? taxProfile?.rfc ?? "—"}
				</FiscalInfoItem>
				<FiscalInfoItem label="Codigo postal">
					{invoiceRequest.zipcode ?? taxProfile?.zipcode ?? "—"}
				</FiscalInfoItem>
				<FiscalInfoItem
					label="Regimen fiscal"
					className="sm:col-span-2"
				>
					{invoiceRequest.formatted_tax_regime ??
						taxProfile?.formatted_tax_regime ??
						invoiceRequest.tax_regime ??
						"—"}
				</FiscalInfoItem>
				<FiscalInfoItem label="Uso CFDI" className="sm:col-span-2">
					{invoiceRequest.formatted_cfdi_use ??
						taxProfile?.formatted_cfdi_use ??
						invoiceRequest.cfdi_use ??
						"—"}
				</FiscalInfoItem>
				{taxProfile?.estatus_sat && (
					<FiscalInfoItem label="Estatus SAT">
						{taxProfile.estatus_sat}
					</FiscalInfoItem>
				)}
				{taxProfile?.formatted_profile_updated_at && (
					<FiscalInfoItem label="Información fiscal actualizada">
						{taxProfile.formatted_profile_updated_at}
					</FiscalInfoItem>
				)}
			</div>
		</div>
	);
}

function FiscalCertificateAvailabilityBadge({ exists, hasPath }) {
	if (exists) {
		return <Badge color="emerald">Constancia disponible en S3</Badge>;
	}

	return (
		<Badge color={hasPath ? "amber" : "zinc"}>
			{hasPath ? "Constancia no disponible" : "Sin constancia fiscal"}
		</Badge>
	);
}

function FiscalInfoItem({ label, children, className = "" }) {
	return (
		<div className={`rounded-lg bg-slate-50 px-3 py-2 ${className}`}>
			<p className="text-xs font-semibold uppercase text-slate-400">
				{label}
			</p>
			<p className="mt-1 break-words font-semibold leading-6 text-famedic-darker">
				{children}
			</p>
		</div>
	);
}

function InvoiceDocumentsPanel({ invoice, invoiceRequest, taxProfile }) {
	const invoicePdfRoute = invoice
		? route("invoice", { invoice: invoice.id })
		: null;
	const invoiceXmlRoute = invoice?.invoice_xml
		? route("invoice.xml", { invoice: invoice.id })
		: null;

	const documents = [
		{
			label: "Factura PDF",
			detail: invoicePdfRoute ? "Archivo cargado" : "Pendiente",
			href: invoicePdfRoute,
			icon: DocumentTextIcon,
		},
		{
			label: "Factura XML",
			detail: invoiceXmlRoute ? "Archivo cargado" : "Pendiente",
			href: invoiceXmlRoute,
			icon: DocumentDuplicateIcon,
		},
	];

	return (
		<div className="rounded-xl border border-slate-100 p-4">
			<Subheading>Documentos de facturacion</Subheading>
			<div className="mt-4 grid gap-3 md:grid-cols-2">
				{documents.map((document) => (
					<div
						key={document.label}
						className="flex items-center gap-3 rounded-lg bg-slate-50 p-3"
					>
						<SoftIcon
							icon={document.icon}
							color={document.href ? "emerald" : "slate"}
							className="size-9"
						/>
						<div className="min-w-0 flex-1">
							<p className="text-sm font-semibold text-famedic-darker">
								{document.label}
							</p>
							<p className="text-xs text-slate-500">
								{document.detail}
							</p>
						</div>
						{document.href ? (
							<Button
								href={document.href}
								target="_blank"
								outline
							>
								Ver
							</Button>
						) : (
							<Badge color="slate">Pendiente</Badge>
						)}
					</div>
				))}
			</div>
		</div>
	);
}

function InvoiceRequestWorkflowPanel({ workflow }) {
	const logs = workflow?.status_logs ?? [];

	if (!workflow && logs.length === 0) {
		return null;
	}

	return (
		<div className="rounded-xl border border-slate-100 p-4">
			<Subheading>Historial de solicitud de factura</Subheading>
			<div className="mt-4 grid gap-3">
				{logs.length ? (
					logs.map((log) => (
						<div
							key={log.id}
							className="flex items-start gap-3 rounded-lg bg-slate-50 p-3 text-sm"
						>
							<SoftIcon
								icon={CheckCircleIcon}
								color="emerald"
								className="size-9"
							/>
							<div>
								<p className="font-semibold text-famedic-darker">
									{log.event_label}
								</p>
								<p className="text-slate-500">
									{log.formatted_created_at ?? "—"} ·{" "}
									{log.trigger_label ?? "Sistema"}
								</p>
							</div>
						</div>
					))
				) : (
					<Text className="text-sm text-slate-500">
						Sin eventos registrados para la solicitud.
					</Text>
				)}
			</div>
		</div>
	);
}

function BillingActionsMenu({
	laboratoryPurchase,
	canUploadInvoice = false,
	onInvoiceAction,
}) {
	const invoice = laboratoryPurchase.invoice;
	const invoiceRequest = laboratoryPurchase.invoice_request;
	const customer = laboratoryPurchase.customer;
	const user = customer?.user;
	const taxProfile =
		invoiceRequest?.tax_profile ?? invoiceRequest?.taxProfile ?? null;

	return (
		<>
			<Dropdown>
				<DropdownButton outline>
					Acciones
					<EllipsisHorizontalIcon />
				</DropdownButton>
				<DropdownMenu anchor="bottom end" className="min-w-72">
					{canUploadInvoice && (
						<DropdownItem onClick={onInvoiceAction}>
							<DocumentTextIcon />
							{invoice ? "Actualizar factura" : "Subir factura"}
						</DropdownItem>
					)}
					{invoice && (
						<DropdownItem
							href={route("invoice", { invoice: invoice.id })}
							target="_blank"
						>
							<DocumentArrowDownIcon />
							Ver factura PDF
						</DropdownItem>
					)}
					{invoice?.invoice_xml && (
						<DropdownItem
							href={route("invoice.xml", { invoice: invoice.id })}
							target="_blank"
						>
							<DocumentDuplicateIcon />
							Ver factura XML
						</DropdownItem>
					)}
					{customer?.id && (
						<DropdownItem
							href={route("admin.customers.show", {
								customer: customer.id,
							})}
						>
							<UserCircleIcon />
							Ir a pantalla de cliente
						</DropdownItem>
					)}
					{user?.id && (
						<DropdownItem
							href={route("admin.users.show", { user: user.id })}
						>
							<UserCircleIcon />
							Ir a pantalla de usuario
						</DropdownItem>
					)}
					{customer?.id && (
						<DropdownItem
							href={route("admin.tax-profiles.show", {
								customer: customer.id,
							})}
						>
							<ClipboardDocumentListIcon />
							Ver perfiles fiscales
						</DropdownItem>
					)}
					{taxProfile?.id && (
						<DropdownItem
							href={route(
								"admin.laboratory-billing.tax-profiles.show",
								{ tax_profile: taxProfile.id },
							)}
						>
							<DocumentDuplicateIcon />
							Ver perfil fiscal vinculado
						</DropdownItem>
					)}
				</DropdownMenu>
			</Dropdown>
		</>
	);
}

function ResultsPanel({
	laboratoryPurchase,
	hasResultsAvailable,
	hasManualResults,
	latestResultsAt,
	resultsGdaSummary = null,
}) {
	const [resultsPdf, setResultsPdf] = useState(
		resultsGdaSummary?.results_pdf ?? null,
	);
	const [actionMessage, setActionMessage] = useState(null);
	const [actionError, setActionError] = useState(null);
	const pdfBadge = resultPdfBadge(resultsPdf);
	const syncLogs = resultsGdaSummary?.sync_logs ?? [];
	const notifications = resultsGdaSummary?.notifications ?? [];

	return (
		<SummaryCard
			title="Resultados"
			actions={
				<ResultsActionsMenu
					laboratoryPurchase={laboratoryPurchase}
					orderKey={resultsGdaSummary?.order_key}
					resultsPdf={resultsPdf}
					onResultsPdfUpdated={setResultsPdf}
					onMessage={setActionMessage}
					onError={setActionError}
				/>
			}
		>
			<div className="space-y-5">
				<div className="flex flex-col gap-4 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-start sm:justify-between">
					<div className="flex items-start gap-4">
						<SoftIcon
							icon={DocumentTextIcon}
							color={hasResultsAvailable ? "emerald" : "slate"}
						/>
						<div className="space-y-2">
							<div>
								<p className="font-bold text-famedic-darker">
									{hasResultsAvailable
										? "Resultados disponibles"
										: "Resultados pendientes"}
								</p>
								<p className="mt-1 text-sm leading-6 text-slate-600">
									{hasResultsAvailable
										? `Ultima actualizacion: ${latestResultsAt ?? "disponible"}`
										: "El laboratorio aun no ha liberado resultados para este pedido."}
								</p>
							</div>

							<div className="flex flex-wrap gap-2">
								<Badge color={pdfBadge.color}>
									{pdfBadge.label}
								</Badge>
								{resultsPdf?.freshness_status_label && (
									<Badge
										color={
											resultsPdf.is_stale
												? "amber"
												: resultsPdf.is_manual_result
													? "violet"
													: resultsPdf.available_at_gda
														? "sky"
														: "slate"
										}
									>
										{resultsPdf.freshness_status_label}
									</Badge>
								)}
								{hasManualResults && (
									<Badge color="violet">
										PDF cargado manualmente
									</Badge>
								)}
							</div>
						</div>
					</div>

					<div className="grid gap-2 text-sm sm:min-w-56">
						<div className="flex justify-between gap-3">
							<span className="text-slate-500">
								Notificaciones
							</span>
							<span className="font-semibold tabular-nums">
								{resultsPdf?.results_notifications_count ?? 0}
							</span>
						</div>
						<div className="flex justify-between gap-3">
							<span className="text-slate-500">
								Disponible GDA
							</span>
							<span className="font-semibold">
								{resultsPdf?.available_at_gda ? "Sí" : "No"}
							</span>
						</div>
						<div className="flex justify-between gap-3">
							<span className="text-slate-500">En S3</span>
							<span className="font-semibold">
								{resultsPdf?.has_pdf_in_storage ? "Sí" : "No"}
							</span>
						</div>
					</div>
				</div>

				{resultsPdf?.has_newer_results && (
					<div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
						<strong>Hay resultados más recientes.</strong> El PDF
						puede estar desactualizado respecto a la última
						notificación de GDA.
					</div>
				)}

				{actionMessage && (
					<div className="rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
						{actionMessage}
					</div>
				)}

				{actionError && (
					<div className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
						{actionError}
					</div>
				)}

				<div className="grid gap-3 text-sm md:grid-cols-2">
					<ResultMeta label="Resultado manual">
						{resultsPdf?.is_manual_result ? "Sí" : "No"}
					</ResultMeta>
					<ResultMeta label="Resultado automático GDA">
						{resultsPdf?.is_gda_automatic ? "Sí" : "No"}
					</ResultMeta>
					<ResultMeta label="Tipo de PDF">
						{resultsPdf?.pdf_kind_label ?? "Sin PDF"}
					</ResultMeta>
					<ResultMeta label="Origen actual">
						{resultsPdf?.pdf_source_label ?? "Sin PDF"}
					</ResultMeta>
					<ResultMeta label="Última notificación GDA">
						{formatDateTime(resultsPdf?.latest_results_at)}
					</ResultMeta>
					<ResultMeta label="PDF almacenado">
						{formatDateTime(resultsPdf?.stored_pdf_at)}
					</ResultMeta>
					<ResultMeta label="ID consulta GDA">
						{resultsPdf?.gda_consult_id ?? "—"}
					</ResultMeta>
					<ResultMeta label="Fuente ID consulta">
						{resultsPdf?.gda_consult_id_source_label ?? "—"}
					</ResultMeta>
					<ResultMeta label="Última descarga vía API GDA">
						{formatDateTime(resultsPdf?.pdf_fetched_at)}
					</ResultMeta>
					<ResultMeta label="Última sincronización a storage">
						{formatDateTime(resultsPdf?.last_sync_at)}
					</ResultMeta>
					<ResultMeta label="Ruta storage" className="md:col-span-2">
						{maskStoragePath(resultsPdf?.storage_path)}
					</ResultMeta>
				</div>

				{resultsPdf?.last_sync_error && (
					<div className="rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700">
						<strong>Último error de sync:</strong>{" "}
						{resultsPdf.last_sync_error}
					</div>
				)}

				{resultsPdf?.last_gda_not_available_at &&
					!resultsPdf.has_pdf_in_storage &&
					!resultsPdf.has_pdf_in_db && (
						<div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
							<strong>Estado API GDA:</strong> Resultado
							notificado, PDF aún no disponible.
							<p className="mt-1 text-xs">
								Último intento:{" "}
								{formatDateTime(
									resultsPdf.last_gda_not_available_at,
								)}
							</p>
							{resultsPdf.last_gda_not_available_message && (
								<p className="text-xs">
									Mensaje GDA:{" "}
									{resultsPdf.last_gda_not_available_message}
								</p>
							)}
						</div>
					)}

				<ResultsSyncLogs logs={syncLogs} />
				<ResultsNotificationsList notifications={notifications} />
			</div>
		</SummaryCard>
	);
}

function ResultMeta({ label, children, className = "" }) {
	return (
		<div className={`rounded-lg border border-slate-100 p-3 ${className}`}>
			<p className="text-xs font-semibold uppercase text-slate-400">
				{label}
			</p>
			<p className="mt-1 break-words font-semibold text-famedic-darker">
				{children}
			</p>
		</div>
	);
}

function ResultsActionsMenu({
	laboratoryPurchase,
	orderKey,
	resultsPdf,
	onResultsPdfUpdated,
	onMessage,
	onError,
}) {
	const [busyAction, setBusyAction] = useState(null);
	const [manualResultsOpen, setManualResultsOpen] = useState(false);

	const canDownload = Boolean(resultsPdf?.can_download && orderKey);
	const canFetchFromGda = Boolean(resultsPdf?.can_fetch_from_gda && orderKey);
	const canForceRefresh = Boolean(
		resultsPdf?.can_force_refresh_from_gda && orderKey,
	);

	const runAction = async (action, callback) => {
		setBusyAction(action);
		onMessage(null);
		onError(null);

		try {
			await callback();
		} catch (error) {
			onError(
				error instanceof Error
					? error.message
					: "No se pudo completar la operación.",
			);
		} finally {
			setBusyAction(null);
		}
	};

	const syncFromGda = () =>
		runAction("sync", async () => {
			const response = await axios.post(
				route("admin.laboratory-notifications-monitor.fetch-results", {
					orderKey,
				}),
			);
			onMessage(response.data?.message ?? "Sincronización completada.");
			if (response.data?.results_pdf) {
				onResultsPdfUpdated(response.data.results_pdf);
			}
			if (response.data?.pdf_base64) {
				openPdfFromBase64(response.data.pdf_base64);
			}
		});

	const forceRefreshFromGda = () => {
		const confirmMsg = resultsPdf?.is_manual_result
			? "Este resultado fue subido manualmente. ¿Deseas sobrescribirlo con el resultado de GDA?"
			: "¿Actualizar desde GDA? Se consultará el resultado más reciente y se guardará en storage/S3.";

		if (!window.confirm(confirmMsg)) {
			return;
		}

		runAction("refresh", async () => {
			const response = await axios.post(
				route(
					"admin.laboratory-notifications-monitor.force-refresh-results",
					{ orderKey },
				),
			);
			onMessage(response.data?.message ?? "Actualización completada.");
			if (response.data?.results_pdf) {
				onResultsPdfUpdated(response.data.results_pdf);
			}
			if (response.data?.pdf_base64) {
				openPdfFromBase64(response.data.pdf_base64);
			}
		});
	};

	const downloadPdf = () =>
		runAction("download", async () => {
			const response = await fetch(
				route(
					"admin.laboratory-notifications-monitor.download-results",
					{
						orderKey,
					},
				),
				{
					headers: {
						Accept: "application/pdf",
						"X-Requested-With": "XMLHttpRequest",
					},
					credentials: "same-origin",
				},
			);

			if (!response.ok) {
				const json = await response.json().catch(() => null);
				throw new Error(
					json?.message ?? "No se pudo descargar el PDF.",
				);
			}

			const blob = await response.blob();
			const url = window.URL.createObjectURL(blob);
			const link = document.createElement("a");
			const disposition =
				response.headers.get("Content-Disposition") ?? "";
			const filenameMatch = disposition.match(/filename="([^"]+)"/);
			link.href = url;
			link.download = filenameMatch?.[1] ?? `resultados_${orderKey}.pdf`;
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);
			onMessage("Descarga del PDF completada.");
		});

	return (
		<>
			<Dropdown>
				<DropdownButton outline>
					Acciones
					<EllipsisHorizontalIcon />
				</DropdownButton>
				<DropdownMenu anchor="bottom end" className="min-w-72">
					{canDownload && (
						<DropdownItem
							onClick={downloadPdf}
							disabled={busyAction !== null}
						>
							<DocumentArrowDownIcon />
							{busyAction === "download"
								? "Descargando..."
								: "Descargar PDF"}
						</DropdownItem>
					)}
					{canFetchFromGda && (
						<DropdownItem
							onClick={syncFromGda}
							disabled={busyAction !== null}
						>
							<ArrowPathIcon />
							{busyAction === "sync"
								? "Sincronizando..."
								: "Sincronizar desde GDA a S3"}
						</DropdownItem>
					)}
					{canForceRefresh && (
						<DropdownItem
							onClick={forceRefreshFromGda}
							disabled={busyAction !== null}
						>
							<ArrowPathIcon />
							{busyAction === "refresh"
								? "Actualizando..."
								: "Actualizar desde GDA"}
						</DropdownItem>
					)}
					<DropdownItem onClick={() => setManualResultsOpen(true)}>
						<BeakerIcon />
						{laboratoryPurchase.results
							? "Gestionar PDF manual"
							: "Subir resultados manualmente"}
					</DropdownItem>
				</DropdownMenu>
			</Dropdown>

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
				open={manualResultsOpen}
				onOpenChange={setManualResultsOpen}
				hideTrigger
			/>
		</>
	);
}

function ResultsSyncLogs({ logs }) {
	if (!logs.length) {
		return (
			<div className="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">
				Sin logs de sincronización GDA para resultados.
			</div>
		);
	}

	return (
		<div className="space-y-3">
			<div>
				<Subheading>Logs de sincronización GDA</Subheading>
				<Text className="text-sm text-slate-500">
					Registro de descargas, storage y estado de email.
				</Text>
			</div>
			<div className="overflow-x-auto rounded-xl border border-slate-200">
				<table className="min-w-full divide-y divide-slate-100 text-sm">
					<thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
						<tr>
							<th className="px-3 py-3">Notificación</th>
							<th className="px-3 py-3">Recibida</th>
							<th className="px-3 py-3">Fuente</th>
							<th className="px-3 py-3">Archivo storage</th>
							<th className="px-3 py-3">Estado</th>
							<th className="px-3 py-3">Email</th>
						</tr>
					</thead>
					<tbody className="divide-y divide-slate-100 bg-white">
						{logs.map((log) => (
							<tr key={log.notification_id}>
								<td className="px-3 py-3 font-semibold">
									#{log.notification_id}
									<p className="text-xs font-normal text-slate-500">
										{log.gda_consecutivo ??
											log.gda_order_id ??
											"—"}
									</p>
								</td>
								<td className="px-3 py-3 text-slate-600">
									{log.received_at ?? "—"}
								</td>
								<td className="px-3 py-3">
									{log.results_source ? (
										<Badge color="emerald">
											{log.results_source}
										</Badge>
									) : (
										"—"
									)}
								</td>
								<td className="px-3 py-3 text-slate-600">
									{maskStoragePath(log.results_storage_path)}
								</td>
								<td className="px-3 py-3">
									{log.results_storage_error ? (
										<span className="font-semibold text-red-600">
											Error
										</span>
									) : (
										(log.status ?? "—")
									)}
								</td>
								<td className="px-3 py-3 text-slate-600">
									{log.email_sent_at ?? log.email ?? "—"}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</div>
	);
}

function ResultsNotificationsList({ notifications }) {
	if (!notifications.length) {
		return null;
	}

	return (
		<div className="space-y-3">
			<Subheading>Interacciones de resultados</Subheading>
			<div className="grid gap-3">
				{notifications.map((notification) => (
					<div
						key={notification.id}
						className="grid gap-3 rounded-lg border border-slate-100 p-3 text-sm md:grid-cols-[minmax(0,1fr)_auto]"
					>
						<div>
							<p className="font-semibold text-famedic-darker">
								Notificación #{notification.id}
							</p>
							<p className="text-slate-500">
								Recibida:{" "}
								{notification.formatted_results_received_at ??
									notification.formatted_created_at ??
									"—"}
							</p>
							{notification.email_recipient_email && (
								<p className="break-all text-xs text-slate-500">
									Email: {notification.email_recipient_email}
								</p>
							)}
						</div>
						<div className="flex flex-wrap items-start gap-2 md:justify-end">
							{notification.gda_status && (
								<Badge color="sky">
									GDA {notification.gda_status}
								</Badge>
							)}
							{notification.has_pdf_in_db && (
								<Badge color="violet">PDF en BD</Badge>
							)}
							{notification.pdf_at_gda && (
								<Badge color="amber">PDF en GDA</Badge>
							)}
							{notification.pdf_source && (
								<Badge color="emerald">
									{notification.pdf_source}
								</Badge>
							)}
						</div>
					</div>
				))}
			</div>
		</div>
	);
}

function HistoryPanel({
	laboratoryPurchase,
	hasSampleCollected,
	hasResultsAvailable,
	latestSampleCollectionAt,
	latestResultsAt,
}) {
	const hasPayment = laboratoryPurchase.transactions.length > 0;
	const hasAppointment = Boolean(laboratoryPurchase.laboratory_appointment);

	return (
		<SummaryCard title="Historial del pedido">
			<ProgressPanel
				hasPayment={hasPayment}
				hasAppointment={hasAppointment}
				hasSampleCollected={hasSampleCollected}
				hasResultsAvailable={hasResultsAvailable}
				invoiceRequest={laboratoryPurchase.invoice_request}
				hasInvoice={!!laboratoryPurchase.invoice}
				createdAt={laboratoryPurchase.formatted_created_at}
				appointmentAt={
					laboratoryPurchase.laboratory_appointment
						?.formatted_appointment_date
				}
				latestSampleCollectionAt={latestSampleCollectionAt}
				latestResultsAt={latestResultsAt}
			/>
		</SummaryCard>
	);
}

function Patient({ laboratoryPurchase, compact = false }) {
	const holder = laboratoryPurchase.customer?.user;
	const patientName = laboratoryPurchase.full_name ?? "Paciente";
	const holderName = holder?.full_name || holder?.email || "Titular";

	return (
		<SummaryCard
			title="Informacion del titular y paciente"
			actions={
				<PatientActionsMenu
					customer={laboratoryPurchase.customer}
					user={holder}
				/>
			}
		>
			<div className={`grid gap-6 ${compact ? "" : "lg:grid-cols-2"}`}>
				<section className="space-y-3">
					<div className="flex items-center gap-4">
						<div className="flex size-14 items-center justify-center rounded-full bg-emerald-50 text-lg font-bold text-emerald-700">
							{getInitials(holderName, "T")}
						</div>
						<div>
							<p className="text-xs font-semibold uppercase text-slate-500">
								Titular
							</p>
							<p className="font-bold text-famedic-darker">
								{holderName}
							</p>
							<span className="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
								Cliente verificado
							</span>
						</div>
					</div>
					<SummaryField label="Correo">
						{holder?.email ?? "No registrado"}
					</SummaryField>
					<SummaryField label="Teléfono">
						{holder?.phone ? (
							<PhoneButton
								phone={holder.phone}
								fullPhone={holder.full_phone}
								countryCode={holder.phone_country}
							/>
						) : (
							"No registrado"
						)}
					</SummaryField>
					<SummaryField label="Cuenta desde">
						{laboratoryPurchase.customer?.formatted_created_at ??
							"No registrado"}
					</SummaryField>
				</section>

				<section
					className={`space-y-3 border-t border-slate-100 pt-6 dark:border-white/5 ${
						compact
							? ""
							: "lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0"
					}`}
				>
					<div className="flex items-center gap-4">
						<div className="flex size-14 items-center justify-center rounded-full bg-blue-50 text-lg font-bold text-blue-600">
							{getInitials(patientName, "P")}
						</div>
						<div>
							<p className="text-xs font-semibold uppercase text-slate-500">
								Paciente
							</p>
							<p className="font-bold text-famedic-darker">
								{patientName}
							</p>
						</div>
					</div>
					<SummaryField label="Sexo">
						{laboratoryPurchase.formatted_gender ?? "No registrado"}
					</SummaryField>
					<SummaryField label="Nacimiento">
						{laboratoryPurchase.formatted_birth_date ??
							"No registrado"}
					</SummaryField>
					<SummaryField label="Teléfono">
						{laboratoryPurchase.phone ? (
							<PhoneButton
								phone={laboratoryPurchase.phone}
								fullPhone={laboratoryPurchase.full_phone}
								countryCode={laboratoryPurchase.phone_country}
							/>
						) : (
							"No registrado"
						)}
					</SummaryField>
				</section>
			</div>
		</SummaryCard>
	);
}

function PatientActionsMenu({ customer, user }) {
	return (
		<Dropdown>
			<DropdownButton outline>
				Acciones
				<EllipsisHorizontalIcon />
			</DropdownButton>
			<DropdownMenu anchor="bottom end">
				{customer?.id && (
					<DropdownItem
						href={route("admin.customers.show", {
							customer: customer.id,
						})}
					>
						<UserCircleIcon />
						Ver cliente
					</DropdownItem>
				)}
				{user?.id && (
					<DropdownItem
						href={route("admin.users.show", { user: user.id })}
					>
						<UserCircleIcon />
						Ver usuario
					</DropdownItem>
				)}
			</DropdownMenu>
		</Dropdown>
	);
}

function Order({ laboratoryPurchase }) {
	const totals = buildLaboratoryPurchaseTotals(laboratoryPurchase);

	return (
		<SummaryCard title="Resumen del pedido" className="lg:col-span-1">
			<div className="space-y-3">
				<div className="flex items-center justify-between gap-4 text-sm">
					<p className="font-semibold text-famedic-darker">
						Estudios incluidos
					</p>
					<span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
						{laboratoryPurchase.laboratory_purchase_items.length}{" "}
						estudios
					</span>
				</div>

				<div className="space-y-2">
					{laboratoryPurchase.laboratory_purchase_items.map(
						(item) => (
							<div
								key={item.id}
								className="flex items-start justify-between gap-4 rounded-lg bg-slate-50 px-3 py-3 text-sm dark:bg-zinc-950/50"
							>
								<div className="min-w-0">
									<p className="truncate font-semibold text-famedic-darker">
										{item.name}
									</p>
									<p className="text-xs text-slate-500">
										Codigo: {item.id}
									</p>
								</div>
								<p className="shrink-0 font-semibold tabular-nums">
									{item.formatted_price}
								</p>
							</div>
						),
					)}
				</div>

				{laboratoryPurchase.laboratory_purchase_items.some(
					(item) =>
						normalizePackageFeatureLabels(item.feature_list)
							.length > 0,
				) && (
					<details className="rounded-md border border-orange-200 bg-orange-50/70 p-3 text-xs dark:border-orange-900/60 dark:bg-orange-950/30">
						<summary className="cursor-pointer font-semibold text-orange-800 dark:text-orange-200">
							Ver contenidos de paquetes
						</summary>
						<div className="mt-3 space-y-3 text-zinc-700 dark:text-slate-300">
							{laboratoryPurchase.laboratory_purchase_items.map(
								(item) => {
									const packageFeatures =
										normalizePackageFeatureLabels(
											item.feature_list,
										);

									if (packageFeatures.length === 0) {
										return null;
									}

									return (
										<div key={item.id}>
											<p className="font-semibold">
												{item.name}
											</p>
											<ul className="mt-1 list-disc space-y-0.5 pl-4">
												{packageFeatures.map(
													(label, idx) => (
														<li
															key={`${item.id}-f-${idx}`}
														>
															{label}
														</li>
													),
												)}
											</ul>
										</div>
									);
								},
							)}
						</div>
					</details>
				)}
			</div>

			<div className="grid gap-3 rounded-lg border border-slate-100 bg-white p-4 text-sm shadow-sm dark:border-white/5 dark:bg-zinc-950/30">
				<div className="flex justify-between gap-4">
					<span className="text-slate-500">Total de estudios</span>
					<span className="font-semibold tabular-nums">
						{laboratoryPurchase.laboratory_purchase_items.length}
					</span>
				</div>
				<div className="flex justify-between gap-4">
					<span className="text-slate-500">Subtotal</span>
					<span className="font-semibold tabular-nums">
						{totals.subtotal}
					</span>
				</div>
				{laboratoryPurchase.coupon_discount_cents > 0 && (
					<div className="flex justify-between gap-4 text-violet-800 dark:text-violet-200">
						<span>Crédito a favor</span>
						<span className="font-medium tabular-nums">
							−{laboratoryPurchase.formatted_coupon_discount}
						</span>
					</div>
				)}
				<div className="flex justify-between gap-4 border-t border-slate-100 pt-3 text-base dark:border-white/5">
					<span className="font-bold text-famedic-darker">
						Total pagado
					</span>
					<Strong className="tabular-nums text-emerald-700">
						{laboratoryPurchase.formatted_net_total ??
							totals.netTotal}
					</Strong>
				</div>
			</div>
		</SummaryCard>
	);
}

function AppointmentPanel({ laboratoryPurchase }) {
	const appointment = laboratoryPurchase.laboratory_appointment;
	const hasAppointment = Boolean(appointment);
	const statusConfig = appointment?.deleted_at
		? { label: "Cancelada", color: "red" }
		: appointment?.confirmed_at
			? { label: "Confirmada", color: "emerald" }
			: { label: "Pendiente", color: "amber" };

	if (!hasAppointment) {
		return (
			<EmptyPanel
				icon={CalendarDaysIcon}
				title="Sin cita relacionada"
				description="Aun no hay una cita vinculada con esta compra."
			/>
		);
	}

	return (
		<SummaryCard
			title="Cita"
			className="lg:col-span-2"
			actions={
				appointment?.id && (
					<Button
						href={route("admin.laboratory-appointments.show", {
							laboratory_appointment: appointment.id,
						})}
						outline
					>
						<CalendarDaysIcon />
						Ver cita
					</Button>
				)
			}
		>
			<div className="space-y-4">
				<div className="space-y-4">
					<div className="grid gap-3 md:grid-cols-4">
						<AppointmentMetricCard
							icon={CalendarDaysIcon}
							label="Estado"
							value={statusConfig.label}
							tone={statusConfig.color}
						/>
						<AppointmentMetricCard
							icon={CalendarDaysIcon}
							label="Fecha"
							value={
								appointment.formatted_appointment_date ??
								"Sin fecha"
							}
							tone="blue"
							className="md:col-span-2"
						/>
						<AppointmentMetricCard
							icon={BeakerIcon}
							label="Laboratorio"
							value={
								appointment.brand?.toUpperCase?.() ??
								laboratoryPurchase.brand?.toUpperCase?.() ??
								"Laboratorio"
							}
							tone="violet"
						/>
					</div>

					<div className="grid gap-4 lg:grid-cols-2">
						<div className="rounded-xl border border-slate-100 bg-slate-50/70 p-4">
							<div className="mb-4 flex items-center gap-3">
								<SoftIcon
									icon={MapPinIcon}
									color="blue"
									className="size-10"
								/>
								<div>
									<p className="text-xs font-semibold uppercase text-slate-400">
										Sucursal asignada
									</p>
									<p className="font-bold text-famedic-darker">
										{appointment.laboratory_store?.name ??
											"Sin sucursal asignada"}
									</p>
								</div>
							</div>
							<div className="space-y-3">
								<SummaryField label="Dirección">
									{appointment.laboratory_store?.address ??
										"No registrada"}
								</SummaryField>
								<SummaryField label="Solicitud">
									{appointment.formatted_request_saved_at ??
										appointment.formatted_created_at ??
										"No registrada"}
								</SummaryField>
								<SummaryField label="Confirmación">
									{appointment.formatted_confirmed_at ??
										"Pendiente"}
								</SummaryField>
							</div>
						</div>

						<div className="rounded-xl border border-slate-100 bg-white p-4">
							<div className="mb-4 flex items-center gap-3">
								<SoftIcon
									icon={UserCircleIcon}
									color="emerald"
									className="size-10"
								/>
								<div>
									<p className="text-xs font-semibold uppercase text-slate-400">
										Paciente de la cita
									</p>
									<p className="font-bold text-famedic-darker">
										{appointment.patient_full_name ??
											laboratoryPurchase.full_name ??
											"Paciente"}
									</p>
								</div>
							</div>
							<div className="space-y-3">
								<SummaryField label="Sexo">
									{appointment.formatted_patient_gender ??
										laboratoryPurchase.formatted_gender ??
										"No registrado"}
								</SummaryField>
								<SummaryField label="Nacimiento">
									{appointment.formatted_patient_birth_date ??
										laboratoryPurchase.formatted_birth_date ??
										"No registrado"}
								</SummaryField>
								<SummaryField label="Teléfono">
									{appointment.patient_phone ? (
										<PhoneButton
											phone={appointment.patient_phone}
											fullPhone={
												appointment.patient_full_phone
											}
											countryCode={
												appointment.patient_phone_country
											}
										/>
									) : laboratoryPurchase.phone ? (
										<PhoneButton
											phone={laboratoryPurchase.phone}
											fullPhone={
												laboratoryPurchase.full_phone
											}
											countryCode={
												laboratoryPurchase.phone_country
											}
										/>
									) : (
										"No registrado"
									)}
								</SummaryField>
							</div>
						</div>
					</div>
				</div>
			</div>
		</SummaryCard>
	);
}

function SampleCollectionPanel({
	laboratoryPurchase,
	sampleCollectionNotifications = [],
}) {
	const appointment = laboratoryPurchase.laboratory_appointment;
	const hasAppointment = Boolean(appointment);

	if (!hasAppointment && sampleCollectionNotifications.length === 0) {
		return (
			<EmptyPanel
				icon={CalendarDaysIcon}
				title="Sin toma de muestra registrada"
				description="Aun no hay cita de toma de muestra asignada ni notificaciones GDA para este pedido."
			/>
		);
	}

	return (
		<SummaryCard title="Cita de toma de muestra" className="lg:col-span-2">
			<div className="space-y-4">
				{hasAppointment && (
					<div className="flex flex-col gap-4 rounded-xl border border-slate-100 bg-slate-50/70 p-4 sm:flex-row">
						<div className="flex h-28 w-full items-center justify-center rounded-lg bg-gradient-to-br from-blue-50 to-violet-50 text-slate-400 sm:w-44">
							<MapPinIcon className="size-10" />
						</div>
						<div className="flex-1 space-y-3">
							<SummaryField label="Fecha">
								{appointment.formatted_appointment_date ??
									"..."}
							</SummaryField>
							<SummaryField label="Sucursal">
								{appointment.laboratory_store?.name ?? "..."}
							</SummaryField>
							<div className="rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">
								Cita programada
							</div>
						</div>
					</div>
				)}
				{sampleCollectionNotifications.length > 0 && (
					<div className="space-y-3">
						<div>
							<Subheading>
								Notificaciones GDA de toma de muestra
							</Subheading>
							<Text className="text-sm text-slate-500">
								Estos eventos provienen del monitor de
								notificaciones GDA para este pedido.
							</Text>
						</div>

						<div className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200">
							{sampleCollectionNotifications.map(
								(notification) => (
									<div
										key={notification.id}
										className="grid gap-3 bg-white p-4 md:grid-cols-[minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.9fr)]"
									>
										<div className="flex gap-3">
											<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
												<CheckCircleIcon className="size-5" />
											</div>
											<div className="min-w-0">
												<p className="font-semibold text-famedic-darker">
													Toma de muestra notificada
												</p>
												<p className="text-sm text-slate-500">
													{notification.formatted_created_at ??
														"Fecha no disponible"}
												</p>
											</div>
										</div>

										<div className="space-y-1 text-sm">
											<p className="text-xs font-semibold uppercase text-slate-400">
												Referencia GDA
											</p>
											<p className="font-semibold text-famedic-darker">
												{notification.gda_order_id ??
													notification.gda_consecutivo ??
													"Sin referencia"}
											</p>
											{notification.gda_acuse && (
												<p className="break-all text-xs text-slate-500">
													Acuse:{" "}
													{notification.gda_acuse}
												</p>
											)}
										</div>

										<div className="space-y-2 text-sm">
											<div className="flex flex-wrap gap-2">
												{notification.status && (
													<Badge color="emerald">
														{notification.status}
													</Badge>
												)}
												{notification.gda_status && (
													<Badge color="sky">
														GDA{" "}
														{
															notification.gda_status
														}
													</Badge>
												)}
											</div>
											{notification.email_recipient_email && (
												<p className="break-all text-xs text-slate-500">
													Correo:{" "}
													{
														notification.email_recipient_email
													}
												</p>
											)}
											{notification.formatted_email_sent_at && (
												<p className="text-xs text-slate-500">
													Email enviado:{" "}
													{
														notification.formatted_email_sent_at
													}
												</p>
											)}
											{notification.email_error && (
												<p className="text-xs font-semibold text-red-600">
													{notification.email_error}
												</p>
											)}
										</div>
									</div>
								),
							)}
						</div>
					</div>
				)}
			</div>
		</SummaryCard>
	);
}

function AppointmentMetricCard({
	icon: Icon,
	label,
	value,
	tone = "slate",
	className = "",
}) {
	const toneClasses = {
		emerald: "bg-emerald-50 text-emerald-700",
		amber: "bg-amber-50 text-amber-700",
		red: "bg-red-50 text-red-700",
		blue: "bg-blue-50 text-blue-700",
		violet: "bg-violet-50 text-violet-700",
		slate: "bg-slate-50 text-slate-600",
	};

	return (
		<div
			className={`rounded-xl border border-slate-100 bg-white p-4 ${className}`}
		>
			<div className="flex items-center gap-3">
				<span
					className={`inline-flex size-10 shrink-0 items-center justify-center rounded-full ${toneClasses[tone] ?? toneClasses.slate}`}
				>
					<Icon className="size-5" />
				</span>
				<div className="min-w-0">
					<p className="text-xs font-semibold uppercase text-slate-400">
						{label}
					</p>
					<p className="mt-1 truncate font-bold text-famedic-darker">
						{value}
					</p>
				</div>
			</div>
		</div>
	);
}

function ActionsMenu({
	laboratoryPurchase,
	showDeleteButton,
	canResendConfirmationEmail,
	resendForm,
	canUploadInvoice,
	canRecoverGda,
	gdaRecoverPreview,
	recoverGdaOpen,
	setRecoverGdaOpen,
	canReplaceGda,
	gdaReplacePreview,
	replaceGdaOpen,
	setReplaceGdaOpen,
}) {
	const [deleteOpen, setDeleteOpen] = useState(false);

	const { delete: destroy, processing } = useForm({});

	const handleDelete = () => {
		if (!processing) {
			destroy(
				route("admin.laboratory-purchases.destroy", {
					laboratory_purchase: laboratoryPurchase,
				}),
				{ preserveScroll: true },
			);
		}
	};

	const assistanceRoute = route(
		"admin.laboratory-purchases.dev-assistance-request.store",
		{
			laboratory_purchase: laboratoryPurchase.id,
		},
	);
	const dialogActionClass = "w-full justify-start";

	return (
		<div className="flex flex-col items-end gap-1">
			<Dropdown>
				<DropdownButton outline className="w-full sm:w-auto">
					Acciones
					<EllipsisHorizontalIcon />
				</DropdownButton>

				<DropdownMenu anchor="bottom end" className="min-w-72">
					{canResendConfirmationEmail && (
						<DropdownItem
							onClick={() =>
								resendForm.post(
									route(
										"admin.laboratory-purchases.resend-confirmation-email",
										{
											laboratory_purchase:
												laboratoryPurchase.id,
										},
									),
									{ preserveScroll: true },
								)
							}
							disabled={resendForm.processing}
						>
							<EnvelopeIcon />
							{resendForm.processing
								? "Enviando correo..."
								: "Reenviar correo de compra"}
						</DropdownItem>
					)}

					{canUploadInvoice && (
						<div className="col-span-full p-1">
							<InvoiceDialog
								storeRoute={route(
									"admin.laboratory-purchases.invoice",
									{
										laboratory_purchase:
											laboratoryPurchase.id,
									},
								)}
								invoiceRoute={
									laboratoryPurchase.invoice
										? route("invoice", {
												invoice:
													laboratoryPurchase.invoice
														.id,
											})
										: null
								}
								invoiceXmlRoute={
									laboratoryPurchase.invoice?.invoice_xml
										? route("invoice.xml", {
												invoice:
													laboratoryPurchase.invoice
														.id,
											})
										: null
								}
								invoiceRequest={
									laboratoryPurchase.invoice_request
								}
								hasInvoice={!!laboratoryPurchase.invoice}
								className={dialogActionClass}
							/>
						</div>
					)}

					<div className="col-span-full p-1">
						<ResultsDialog
							storeRoute={route(
								"admin.laboratory-purchases.results",
								{
									laboratory_purchase: laboratoryPurchase,
								},
							)}
							resultsRoute={
								laboratoryPurchase.results
									? route("laboratory-purchases.results", {
											laboratory_purchase:
												laboratoryPurchase,
										})
									: null
							}
							hasResults={!!laboratoryPurchase.results}
							className={dialogActionClass}
						/>
					</div>

					<div className="col-span-full p-1">
						{laboratoryPurchase.dev_assistance_requests.length ===
						0 ? (
							<DevAssistanceButton
								storeRoute={assistanceRoute}
								className={dialogActionClass}
							/>
						) : (
							<DevAssistanceDropdown
								requests={
									laboratoryPurchase.dev_assistance_requests
								}
								storeRoute={assistanceRoute}
								resolveRouteName="admin.laboratory-purchases.dev-assistance-request.resolved"
								unresolveRouteName="admin.laboratory-purchases.dev-assistance-request.unresolved"
								routeParams={{
									laboratory_purchase: laboratoryPurchase.id,
								}}
								className={dialogActionClass}
							/>
						)}
					</div>

					{canReplaceGda && gdaReplacePreview && (
						<DropdownItem onClick={() => setReplaceGdaOpen(true)}>
							<DocumentDuplicateIcon />
							Crear pedido de reemplazo
						</DropdownItem>
					)}

					{canRecoverGda && gdaRecoverPreview && (
						<DropdownItem onClick={() => setRecoverGdaOpen(true)}>
							<ArrowPathIcon />
							Recuperar mismo pedido
						</DropdownItem>
					)}

					{showDeleteButton && (
						<DropdownItem onClick={() => setDeleteOpen(true)}>
							<TrashIcon className="stroke-red-500 dark:stroke-red-400" />
							Cancelar pedido
						</DropdownItem>
					)}
				</DropdownMenu>
			</Dropdown>

			{resendForm.errors.resend_confirmation && (
				<Text className="max-w-72 !text-right !text-sm text-red-600">
					{resendForm.errors.resend_confirmation}
				</Text>
			)}

			{canReplaceGda && gdaReplacePreview && (
				<ReplaceGdaModal
					open={replaceGdaOpen}
					onClose={() => setReplaceGdaOpen(false)}
					laboratoryPurchase={laboratoryPurchase}
					preview={gdaReplacePreview}
				/>
			)}

			{canRecoverGda && gdaRecoverPreview && (
				<RecoverGdaModal
					open={recoverGdaOpen}
					onClose={() => setRecoverGdaOpen(false)}
					laboratoryPurchase={laboratoryPurchase}
					preview={gdaRecoverPreview}
				/>
			)}

			<DeleteConfirmationModal
				isOpen={deleteOpen}
				close={() => setDeleteOpen(false)}
				title="Cancelar pedido"
				description="¿Estás seguro de cancelar este pedido?"
				processing={processing}
				destroy={handleDelete}
			/>
		</div>
	);
}
