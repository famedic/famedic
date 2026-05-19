import { useEffect, useMemo, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import SettingsLayout from "@/Layouts/SettingsLayout";
import Layout from "@/Components/LaboratoryOrderDetail/Layout";
import Sidebar from "@/Components/LaboratoryOrderDetail/Sidebar";
import Header from "@/Components/LaboratoryOrderDetail/Header";
import Tabs from "@/Components/LaboratoryOrderDetail/Tabs";
import PatientCard from "@/Components/LaboratoryOrderDetail/PatientCard";
import OrderSummary from "@/Components/LaboratoryOrderDetail/OrderSummary";
import StudiesTable from "@/Components/LaboratoryOrderDetail/StudiesTable";
import AppointmentSummary from "@/Components/LaboratoryOrderDetail/AppointmentSummary";
import InvoiceSection from "@/Components/LaboratoryOrderDetail/InvoiceSection";
import ResultsSection from "@/Components/LaboratoryOrderDetail/ResultsSection";
import OrderTimeline from "@/Components/LaboratoryOrderDetail/OrderTimeline";
import InstructionsContent from "@/Components/LaboratoryOrderDetail/InstructionsContent";
import SecurityVerificationModal from "@/Components/SecurityVerificationModal";
import PurchasePdfDialog from "@/Components/PurchasePdfDialog";
import Card from "@/Components/Card";
import { navigateToLabResults, openLabResultsInNewTabOrSame } from "@/Utils/openLabResultsUrl";
import { isLabResultsOtpRequired } from "@/Utils/labResultsOtp";

function onlyDateLabel(value = "") {
	const raw = String(value || "").trim();
	if (!raw) return null;

	const parsed = new Date(raw);
	if (!Number.isNaN(parsed.getTime())) {
		return parsed.toLocaleDateString("es-MX", {
			day: "2-digit",
			month: "short",
			year: "numeric",
		});
	}

	const parts = raw.split(" ");
	return parts.slice(0, 3).join(" ") || raw;
}

async function fetchLabResultsOtpStatus(purchaseId) {
	try {
		const statusUrl = route("otp.status", { laboratory_purchase: purchaseId });
		const res = await fetch(statusUrl, {
			method: "GET",
			credentials: "same-origin",
			headers: { Accept: "application/json" },
		});
		const data = await res.json().catch(() => ({}));
		return {
			verified: res.ok && Boolean(data?.verified),
			expiresIn: Number(data?.expires_in ?? 0),
		};
	} catch {
		return { verified: false, expiresIn: 0 };
	}
}

export default function LaboratoryOrderDetail({
	laboratoryPurchase,
	isCancelled = false,
	hasSampleCollected,
	hasResultsAvailable,
	latestSampleCollectionAt,
	latestResultsAt,
}) {
	const [activeTab, setActiveTab] = useState("patient");
	const [pendingScrollToPreparation, setPendingScrollToPreparation] = useState(false);
	const [showOtpModal, setShowOtpModal] = useState(false);
	const [otpPurchaseId, setOtpPurchaseId] = useState(null);
	const [isProcessingResults, setIsProcessingResults] = useState(false);
	const [otpStatus, setOtpStatus] = useState({ verified: false, expiresIn: 0 });
	const [showPdfDialog, setShowPdfDialog] = useState(false);
	const [pdfDialogTab, setPdfDialogTab] = useState(0);
	const [shareNotice, setShareNotice] = useState(null);
	const pendingAfterOtpRef = useRef(null);
	const { daysLeftToRequestInvoice = 0, errors: pageErrors = {}, ...pageProps } = usePage().props;
	const labResultsOtpRequired = isLabResultsOtpRequired(pageProps);

	useEffect(() => {
		if (activeTab !== "instructions" || !pendingScrollToPreparation) return;
		const id = "indicaciones-preparacion-estudios";
		const run = () => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
		requestAnimationFrame(() => {
			requestAnimationFrame(run);
		});
		setPendingScrollToPreparation(false);
	}, [activeTab, pendingScrollToPreparation]);

	useEffect(() => {
		const params = new URLSearchParams(window.location.search);
		const tab = params.get("tab");
		if (!tab) return;
		if (tab === "facturas" || tab === "invoice") {
			setActiveTab("invoice");
		}
		if (tab === "instrucciones" || tab === "instructions") {
			setActiveTab("instructions");
		}
	}, []);

	const goToPreparationInstructions = () => {
		setPendingScrollToPreparation(true);
		setActiveTab("instructions");
	};

	const studies = useMemo(() => {
		const priceFormatter = new Intl.NumberFormat("es-MX", {
			style: "currency",
			currency: "MXN",
		});
		return (laboratoryPurchase?.laboratory_purchase_items || []).map((item) => {
			const requiresAppointment = Boolean(
				item?.requires_appointment ??
					item?.requiresAppointment ??
					item?.appointment_required ??
					laboratoryPurchase?.laboratory_appointment,
			);

			const rawFeatures = item.feature_list ?? item.featureList;
			const featureList = Array.isArray(rawFeatures)
				? rawFeatures
				: typeof rawFeatures === "string"
					? (() => {
							try {
								const parsed = JSON.parse(rawFeatures);
								return Array.isArray(parsed) ? parsed : [];
							} catch {
								return [];
							}
						})()
					: [];

			const formattedPrice =
				item.formatted_price || priceFormatter.format(Number(item.price_cents || 0) / 100);
			return {
				id: item.id,
				name: item.name,
				featureList,
				formattedPrice,
				requiresAppointment,
				appointmentStatus: requiresAppointment
					? laboratoryPurchase?.laboratory_appointment
						? "scheduled"
						: "pending"
					: "not_applicable",
				resultsUrl: laboratoryPurchase?.results
					? route("laboratory-purchases.results", {
							laboratory_purchase: laboratoryPurchase.id,
						})
					: null,
			};
		});
	}, [laboratoryPurchase]);

	const appointmentSummary = useMemo(() => {
		const totalWithAppointment = studies.filter((study) => study.requiresAppointment).length;
		const scheduled = studies.filter(
			(study) => study.requiresAppointment && study.appointmentStatus === "scheduled",
		).length;
		const pending = Math.max(totalWithAppointment - scheduled, 0);
		const status =
			totalWithAppointment === 0 ? "complete" : scheduled === 0 ? "pending" : pending > 0 ? "partial" : "complete";
		return { totalWithAppointment, scheduled, pending, status };
	}, [studies]);

	const studiesWithIndications = useMemo(
		() =>
			(laboratoryPurchase?.laboratory_purchase_items || []).map((item) => ({
				id: item.id,
				name: item.name,
				indications: item.indications || "",
			})),
		[laboratoryPurchase],
	);

	const orderType = useMemo(() => {
		const withAppointment = appointmentSummary.totalWithAppointment;
		if (withAppointment === 0) return "without_appointment";
		if (withAppointment === studies.length) return "with_appointment";
		return "mixed";
	}, [appointmentSummary.totalWithAppointment, studies.length]);

	const timelineSteps = useMemo(() => {
		const isSwisslab = (laboratoryPurchase?.provider || "").toLowerCase() === "swisslab";
		const firstTransaction = laboratoryPurchase?.transactions?.[0];
		const purchaseDate = onlyDateLabel(laboratoryPurchase?.formatted_created_at);
		const paymentDate = onlyDateLabel(firstTransaction?.created_at) || purchaseDate;
		const appointmentDate = onlyDateLabel(
			laboratoryPurchase?.laboratory_appointment?.formatted_appointment_date,
		);
		const sampleDate =
			onlyDateLabel(latestSampleCollectionAt) ||
			onlyDateLabel(laboratoryPurchase?.formatted_sample_collection_at);
		const resultsDate =
			onlyDateLabel(latestResultsAt) ||
			onlyDateLabel(laboratoryPurchase?.formatted_results_at) ||
			onlyDateLabel(laboratoryPurchase?.formatted_results_uploaded_at);
		const invoiceRequestDate = onlyDateLabel(laboratoryPurchase?.invoice_request?.created_at);
		const invoiceDate = onlyDateLabel(laboratoryPurchase?.invoice?.created_at);

		const base = [
			{
				key: "created",
				title: "Orden creada",
				status: "completed",
				description: purchaseDate,
			},
			{
				key: "paid",
				title: "Pago confirmado",
				status: laboratoryPurchase?.transactions?.length ? "completed" : "pending",
				description: paymentDate,
			},
		];

		if (orderType === "with_appointment") {
			base.push({
				key: "appointment",
				title: "Cita",
				status: laboratoryPurchase?.laboratory_appointment ? "completed" : "pending",
				description: appointmentDate,
			});
		}

		if (orderType !== "mixed") {
			const processingCompleted =
				hasSampleCollected || hasResultsAvailable || Boolean(laboratoryPurchase?.results);
			base.push(
				isSwisslab
					? {
							key: "sample",
							title: "Muestras",
							status: hasSampleCollected ? "completed" : "pending",
							description: sampleDate,
						}
					: {
							key: "processing",
							title: "Muestras",
							status: processingCompleted ? "completed" : "pending",
							description: sampleDate,
						},
			);
		}

		base.push(
			isSwisslab
				? {
						key: "results",
						title: "Resultados",
						status: hasResultsAvailable ? "completed" : "pending",
						description: resultsDate,
					}
				: {
						key: "manual_results",
						title: "Resultados",
						status: hasResultsAvailable || laboratoryPurchase?.results ? "completed" : "pending",
						description: resultsDate,
					},
		);

		if (laboratoryPurchase?.invoice_request) {
			base.push({
				key: "invoice_request",
				title: "Solicitud de factura",
				status: "completed",
				description: invoiceRequestDate,
			});
		}

		if (laboratoryPurchase?.invoice) {
			base.push({
				key: "invoice_available",
				title: "Factura disponible",
				status: "completed",
				description: invoiceDate,
			});
		}

		return base;
	}, [
		hasSampleCollected,
		hasResultsAvailable,
		laboratoryPurchase,
		latestResultsAt,
		latestSampleCollectionAt,
		orderType,
	]);

	const totals = useMemo(() => {
		const subtotalCents = (laboratoryPurchase?.laboratory_purchase_items || []).reduce(
			(acc, item) => acc + Number(item.price_cents || 0),
			0,
		);
		const totalCents = Number(laboratoryPurchase?.total_cents || subtotalCents);
		const otherDiscountCents = Math.max(subtotalCents - totalCents, 0);
		const formatter = new Intl.NumberFormat("es-MX", {
			style: "currency",
			currency: "MXN",
		});

		const paymentMethodMap = {
			stripe: "Tarjeta",
			odessa: "Caja de ahorro (Odessa)",
			efevoopay: "Tarjeta (Efevoo Pay)",
			paypal: "PayPal",
			coupon_balance: "Crédito a favor",
		};

		const firstTx = laboratoryPurchase?.transactions?.[0];
		const paymentMethodKey =
			laboratoryPurchase?.payment_method || firstTx?.payment_method || "";

		const couponDiscountCents = Number(laboratoryPurchase?.coupon_discount_cents || 0);
		const couponFromTxCents = Number(firstTx?.details?.coupon_amount_cents || 0);
		let creditAppliedCents = Math.max(couponDiscountCents, couponFromTxCents);
		if (creditAppliedCents === 0 && paymentMethodKey === "coupon_balance") {
			creditAppliedCents = subtotalCents;
		}
		const hasAppliedCreditBalance =
			creditAppliedCents > 0 || paymentMethodKey === "coupon_balance";
		const discountDisplayCents = hasAppliedCreditBalance ? creditAppliedCents : otherDiscountCents;

		return {
			subtotal: formatter.format(subtotalCents / 100),
			discount: formatter.format(discountDisplayCents / 100),
			hasAppliedCreditBalance,
			creditAppliedCents,
			total: formatter.format(totalCents / 100),
			paymentMethodLabel: paymentMethodMap[paymentMethodKey] || "No disponible",
			paymentMethodKey,
			cardBrand: firstTx?.details?.card_brand || firstTx?.details?.token_info?.card_brand,
			cardLastFour: firstTx?.details?.card_last_four || firstTx?.details?.token_info?.card_last_four,
			paymentStatusLabel: laboratoryPurchase?.transactions?.length ? "Pagado" : "Pendiente",
			paymentStatusColor: laboratoryPurchase?.transactions?.length ? "green" : "amber",
		};
	}, [laboratoryPurchase]);

	const orderIsCancelled = isCancelled || Boolean(laboratoryPurchase?.deleted_at);

	const canRequestInvoice =
		!orderIsCancelled &&
		!laboratoryPurchase?.invoice &&
		!laboratoryPurchase?.invoice_request &&
		daysLeftToRequestInvoice > 0;

	useEffect(() => {
		if (!laboratoryPurchase?.id || !(hasResultsAvailable || laboratoryPurchase?.results)) return;
		let isMounted = true;
		fetchLabResultsOtpStatus(laboratoryPurchase.id).then((status) => {
			if (isMounted) setOtpStatus(status);
		});
		return () => {
			isMounted = false;
		};
	}, [laboratoryPurchase?.id, hasResultsAvailable, laboratoryPurchase?.results]);

	useEffect(() => {
		if (!otpStatus.verified || otpStatus.expiresIn <= 0) return;
		const timer = setInterval(() => {
			setOtpStatus((prev) => {
				const next = Math.max(0, Number(prev.expiresIn || 0) - 1);
				return { ...prev, expiresIn: next, verified: next > 0 };
			});
		}, 1000);
		return () => clearInterval(timer);
	}, [otpStatus.verified, otpStatus.expiresIn]);

	const cancelledAtLabel = orderIsCancelled && laboratoryPurchase?.deleted_at
		? onlyDateLabel(laboratoryPurchase.deleted_at)
		: null;

	const handleDownloadOrder = () => {
		if (!laboratoryPurchase?.id) return;
		window.open(
			route("laboratory-purchases.download-pdf", {
				laboratory_purchase: laboratoryPurchase.id,
			}),
			"_blank",
			"noopener,noreferrer",
		);
	};

	const handleShareOrder = async () => {
		if (!laboratoryPurchase?.id) return;

		const shareUrl = window.location.href;
		const shareTitle = `Orden de laboratorio ${laboratoryPurchase.gda_order_id || laboratoryPurchase.id}`;
		const sharePayload = {
			title: shareTitle,
			text: "Consulta el detalle de mi orden de laboratorio en Famedic.",
			url: shareUrl,
		};

		if (typeof navigator.share === "function") {
			try {
				const canShare =
					typeof navigator.canShare !== "function" || navigator.canShare(sharePayload);
				if (canShare) {
					await navigator.share(sharePayload);
					return;
				}
			} catch (error) {
				if (error?.name === "AbortError") return;
			}
		}

		if (navigator.clipboard?.writeText) {
			try {
				await navigator.clipboard.writeText(shareUrl);
				setShareNotice("Enlace copiado al portapapeles.");
				window.setTimeout(() => setShareNotice(null), 4000);
				return;
			} catch {
				// Continúa con el diálogo de correo.
			}
		}

		setPdfDialogTab(1);
		setShowPdfDialog(true);
	};

	const header = (
		<Header
			breadcrumb={`Laboratorios / Órdenes / ${laboratoryPurchase?.gda_order_id || laboratoryPurchase?.id}`}
			title="Orden de laboratorio"
			brand={laboratoryPurchase?.brand}
			dateLabel={laboratoryPurchase?.formatted_created_at || "Sin fecha"}
			orderType={orderType}
			canRequestInvoice={canRequestInvoice}
			invoiceDaysLeft={daysLeftToRequestInvoice}
			gdaOrderId={laboratoryPurchase?.gda_order_id}
			gdaConsecutivo={laboratoryPurchase?.gda_consecutivo}
			isCancelled={orderIsCancelled}
			cancelledAtLabel={cancelledAtLabel}
			onRequestInvoice={() => setActiveTab("invoice")}
			onDownload={handleDownloadOrder}
			onShare={handleShareOrder}
		/>
	);

	const tabs = <Tabs activeTab={activeTab} onChange={setActiveTab} />;

	if (!laboratoryPurchase) {
		return (
			<SettingsLayout title="Pedido de laboratorio">
				<Card className="rounded-2xl p-8 text-center text-sm text-zinc-500 dark:text-slate-400">
					No encontramos la orden de laboratorio solicitada.
				</Card>
			</SettingsLayout>
		);
	}

	const requireOtpThen = async (purchaseId, fn) => {
		if (!labResultsOtpRequired) {
			fn?.();
			return true;
		}

		const status = await fetchLabResultsOtpStatus(purchaseId);
		if (status.verified) {
			fn?.();
			return true;
		}
		pendingAfterOtpRef.current = fn;
		setOtpPurchaseId(purchaseId);
		setShowOtpModal(true);
		return false;
	};

	const handleOtpSuccess = async () => {
		setShowOtpModal(false);
		const next = pendingAfterOtpRef.current;
		pendingAfterOtpRef.current = null;
		setOtpPurchaseId(null);
		if (laboratoryPurchase?.id) {
			const status = await fetchLabResultsOtpStatus(laboratoryPurchase.id);
			setOtpStatus(status);
		}
		if (typeof next === "function") next();
	};

	const handleOtpModalClose = () => {
		pendingAfterOtpRef.current = null;
		setShowOtpModal(false);
		setOtpPurchaseId(null);
	};

	const getResultsUrl = () => {
		if (!laboratoryPurchase?.id) return null;
		if (laboratoryPurchase.results) {
			return route("laboratory-purchases.results", { laboratory_purchase: laboratoryPurchase.id });
		}
		return route("laboratory-results.view", { type: "purchase", id: laboratoryPurchase.id });
	};

	const openResultsFromSidebar = () => {
		if (isProcessingResults || !laboratoryPurchase?.id) return;

		const url = getResultsUrl();
		if (!url) return;

		setIsProcessingResults(true);

		void (async () => {
			try {
				if (!labResultsOtpRequired) {
					openLabResultsInNewTabOrSame(url);
					return;
				}

				const allowed = await requireOtpThen(laboratoryPurchase.id, () => {
					navigateToLabResults(url);
				});
				if (allowed) {
					openLabResultsInNewTabOrSame(url);
					const status = await fetchLabResultsOtpStatus(laboratoryPurchase.id);
					setOtpStatus(status);
				}
			} finally {
				setIsProcessingResults(false);
			}
		})();
	};

	const main = (
		<>
			{activeTab === "patient" && (
				<>
					<PatientCard purchase={laboratoryPurchase} />
					<StudiesTable
						studies={studies}
						onOpenPreparationInstructions={goToPreparationInstructions}
					/>
					{laboratoryPurchase?.laboratory_appointment && (
						<AppointmentSummary
							appointment={laboratoryPurchase.laboratory_appointment}
							purchaseId={laboratoryPurchase.id}
						/>
					)}
					<OrderSummary totals={totals} />
				</>
			)}

			{activeTab === "instructions" && (
				<InstructionsContent
					purchase={laboratoryPurchase}
					orderType={orderType}
					hasAppointment={Boolean(laboratoryPurchase?.laboratory_appointment)}
					appointment={laboratoryPurchase?.laboratory_appointment}
					studiesWithIndications={studiesWithIndications}
				/>
			)}

			{activeTab === "invoice" && <InvoiceSection purchase={laboratoryPurchase} inlineForm />}
		</>
	);

	const sidebar = (
		<>
			<Sidebar title="Resultados">
				<ResultsSection
					hasResults={Boolean(hasResultsAvailable || laboratoryPurchase?.results)}
					resultsUploadedAt={latestResultsAt || laboratoryPurchase?.formatted_results_uploaded_at}
					onViewResults={openResultsFromSidebar}
					isProcessing={isProcessingResults}
					otpRequired={labResultsOtpRequired}
					otpVerified={otpStatus.verified}
					otpExpiresIn={otpStatus.expiresIn}
				/>
			</Sidebar>
			{activeTab !== "invoice" && <Sidebar title="Facturas"><InvoiceSection purchase={laboratoryPurchase} /></Sidebar>}
			<Sidebar title="Timeline inteligente">
				<OrderTimeline steps={timelineSteps} />
			</Sidebar>
		</>
	);

	return (
		<SettingsLayout title="Pedido de laboratorio">
			<div className="min-w-0 max-w-full">
				{shareNotice && (
					<p
						className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100"
						role="status"
					>
						{shareNotice}
					</p>
				)}
				{pageErrors?.pdf && (
					<p
						className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100"
						role="alert"
					>
						{pageErrors.pdf}
					</p>
				)}
				<Layout header={header} tabs={tabs} main={main} sidebar={sidebar} />
			</div>
			<PurchasePdfDialog
				laboratoryPurchase={laboratoryPurchase}
				isOpen={showPdfDialog}
				onClose={setShowPdfDialog}
				selectedTab={pdfDialogTab}
				setSelectedTab={setPdfDialogTab}
			/>
			{labResultsOtpRequired && showOtpModal && otpPurchaseId != null && (
				<SecurityVerificationModal
					isOpen={showOtpModal}
					purchaseId={otpPurchaseId}
					onSuccess={handleOtpSuccess}
					onClose={handleOtpModalClose}
				/>
			)}
		</SettingsLayout>
	);
}
