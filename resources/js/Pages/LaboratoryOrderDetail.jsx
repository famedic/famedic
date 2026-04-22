import { useEffect, useMemo, useState } from "react";
import { router, usePage } from "@inertiajs/react";
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
import OrderTimeline from "@/Components/LaboratoryOrderDetail/OrderTimeline";
import InstructionsContent from "@/Components/LaboratoryOrderDetail/InstructionsContent";
import Card from "@/Components/Card";

export default function LaboratoryOrderDetail({
	laboratoryPurchase,
	hasSampleCollected,
	hasResultsAvailable,
	latestSampleCollectionAt,
	latestResultsAt,
}) {
	const [activeTab, setActiveTab] = useState("patient");
	const [pendingScrollToPreparation, setPendingScrollToPreparation] = useState(false);
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

				return {
					id: item.id,
					name: item.name,
					requiresAppointment,
					appointmentStatus: requiresAppointment
						? laboratoryPurchase?.laboratory_appointment
							? "scheduled"
							: "pending"
						: "not_applicable",
					studyStatus: hasResultsAvailable
						? "completed"
						: hasSampleCollected
							? "in_progress"
							: "pending",
					resultsUrl: laboratoryPurchase?.results
						? route("laboratory.results.show", {
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
							status: hasSampleCollected ? "completed" : "pending",
							description: "Seguimiento manual por administracion",
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
						title: "Resultados cargados por administrador",
						status: hasResultsAvailable || laboratoryPurchase?.results ? "completed" : "pending",
						description: latestResultsAt || "Carga pendiente",
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

	const main = (
		<>
			{activeTab === "patient" && (
				<>
					<PatientCard purchase={laboratoryPurchase} />
					<StudiesTable
						studies={studies}
						onOpenResults={() =>
							router.visit(route("laboratory-purchases.show", {
								laboratory_purchase: laboratoryPurchase.id,
								tab: "resultados",
							}))
						}
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

			{activeTab === "invoice" && <InvoiceSection purchase={laboratoryPurchase} />}
		</>
	);

	const sidebar = (
		<>
			<Sidebar title="Timeline inteligente">
				<OrderTimeline steps={timelineSteps} />
			</Sidebar>
			{activeTab !== "invoice" && <InvoiceSection purchase={laboratoryPurchase} />}
		</>
	);

	return (
		<SettingsLayout title="Pedido de laboratorio">
			<div className="min-w-0 max-w-full">
				<Layout header={header} tabs={tabs} main={main} sidebar={sidebar} />
			</div>
		</SettingsLayout>
	);
}
