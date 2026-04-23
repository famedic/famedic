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
import Card from "@/Components/Card";

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
	const pendingAfterOtpRef = useRef(null);
	const { daysLeftToRequestInvoice = 0 } = usePage().props;

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

	const studies = useMemo(
		() =>
			(laboratoryPurchase?.laboratory_purchase_items || []).map((item) => {
				const requiresAppointment = Boolean(
					item?.requires_appointment ??
						item?.requiresAppointment ??
						item?.appointment_required ??
						laboratoryPurchase?.laboratory_appointment,
				);

				const hasAnyResults = hasResultsAvailable || Boolean(laboratoryPurchase?.results);
				return {
					id: item.id,
					name: item.name,
					requiresAppointment,
					appointmentStatus: requiresAppointment
						? laboratoryPurchase?.laboratory_appointment
							? "scheduled"
							: "pending"
						: "not_applicable",
					studyStatus: hasAnyResults
						? "completed"
						: hasSampleCollected
							? "in_progress"
							: "pending",
					resultsUrl: laboratoryPurchase?.results
						? route("laboratory-purchases.results", {
								laboratory_purchase: laboratoryPurchase.id,
							})
						: null,
				};
			}),
		[laboratoryPurchase, hasResultsAvailable, hasSampleCollected],
	);

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
		const base = [
			{ key: "created", title: "Orden creada", status: "completed" },
			{
				key: "paid",
				title: "Pago confirmado",
				status: laboratoryPurchase?.transactions?.length ? "completed" : "pending",
			},
		];

		if (orderType === "with_appointment") {
			base.push({
				key: "appointment",
				title: "Cita",
				status: laboratoryPurchase?.laboratory_appointment ? "completed" : "pending",
			});
		}

		if (orderType !== "mixed") {
			const processingCompleted =
				hasSampleCollected || hasResultsAvailable || Boolean(laboratoryPurchase?.results);
			base.push(
				isSwisslab
					? {
							key: "sample",
							title: "Toma de muestra",
							status: hasSampleCollected ? "completed" : "pending",
							description: latestSampleCollectionAt || "Pendiente de toma de muestra",
						}
					: {
							key: "processing",
							title: "Procesando",
							status: processingCompleted ? "completed" : "pending",
							description: "...",
						},
			);
		}

		base.push(
			isSwisslab
				? {
						key: "results",
						title: "Resultados disponibles",
						status: hasResultsAvailable ? "completed" : "pending",
						description: latestResultsAt || "Aun sin resultados",
					}
				: {
						key: "manual_results",
						title: "Resultados cargados",
						status: hasResultsAvailable || laboratoryPurchase?.results ? "completed" : "pending",
						description: latestResultsAt || "Cargados",
					},
		);

		base.push(
			{
				key: "invoice_request",
				title: "Solicitud de factura",
				status: laboratoryPurchase?.invoice_request ? "completed" : "pending",
			},
			{
				key: "invoice_available",
				title: "Factura disponible",
				status: laboratoryPurchase?.invoice ? "completed" : "pending",
			},
		);

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
		const discountCents = Math.max(subtotalCents - totalCents, 0);
		const formatter = new Intl.NumberFormat("es-MX", {
			style: "currency",
			currency: "MXN",
		});

		const paymentMethodMap = {
			stripe: "Tarjeta",
			odessa: "Saldo Odessa",
			efevoopay: "Pago en línea",
		};

		const firstTx = laboratoryPurchase?.transactions?.[0];
		const paymentMethodKey =
			laboratoryPurchase?.payment_method || firstTx?.payment_method || "";

		return {
			subtotal: formatter.format(subtotalCents / 100),
			discount: formatter.format(discountCents / 100),
			total: formatter.format(totalCents / 100),
			paymentMethod: paymentMethodMap[paymentMethodKey] || "No disponible",
			paymentMethodKey,
			cardBrand: firstTx?.details?.card_brand,
			cardLastFour: firstTx?.details?.card_last_four,
			paymentStatusLabel: laboratoryPurchase?.transactions?.length ? "Pagado" : "Pendiente",
			paymentStatusColor: laboratoryPurchase?.transactions?.length ? "green" : "amber",
		};
	}, [laboratoryPurchase]);

	const canRequestInvoice =
		!laboratoryPurchase?.invoice &&
		!laboratoryPurchase?.invoice_request &&
		daysLeftToRequestInvoice > 0;

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
			onRequestInvoice={() => setActiveTab("invoice")}
			onDownload={() => window.open(route("laboratory-purchases.show", laboratoryPurchase?.id), "_blank")}
			onShare={() =>
				navigator.clipboard?.writeText(window.location.href).then(() => {
					// no-op
				})
			}
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

	const handleOtpSuccess = () => {
		setShowOtpModal(false);
		const next = pendingAfterOtpRef.current;
		pendingAfterOtpRef.current = null;
		setOtpPurchaseId(null);
		if (typeof next === "function") next();
	};

	const handleOtpModalClose = () => {
		pendingAfterOtpRef.current = null;
		setShowOtpModal(false);
		setOtpPurchaseId(null);
	};

	const openResultsFromSidebar = async () => {
		if (isProcessingResults) return;
		setIsProcessingResults(true);
		const openResults = () => {
			if (laboratoryPurchase?.results) {
				window.open(
					route("laboratory-purchases.results", { laboratory_purchase: laboratoryPurchase.id }),
					"_blank",
					"noopener,noreferrer",
				);
				return;
			}
			window.open(
				route("laboratory-results.view", { type: "purchase", id: laboratoryPurchase.id }),
				"_blank",
				"noopener,noreferrer",
			);
		};
		try {
			await requireOtpThen(laboratoryPurchase.id, openResults);
		} finally {
			setIsProcessingResults(false);
		}
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
			<Sidebar title="Timeline inteligente">
				<OrderTimeline steps={timelineSteps} />
			</Sidebar>
			<Sidebar title="Resultados">
				<ResultsSection
					hasResults={Boolean(hasResultsAvailable || laboratoryPurchase?.results)}
					resultsUploadedAt={latestResultsAt || laboratoryPurchase?.formatted_results_uploaded_at}
					onViewResults={openResultsFromSidebar}
					isProcessing={isProcessingResults}
				/>
			</Sidebar>
			{activeTab !== "invoice" && <InvoiceSection purchase={laboratoryPurchase} />}
		</>
	);

	return (
		<SettingsLayout title="Pedido de laboratorio">
			<div className="min-w-0 max-w-full">
				<Layout header={header} tabs={tabs} main={main} sidebar={sidebar} />
			</div>
			{showOtpModal && otpPurchaseId != null && (
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
