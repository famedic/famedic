import SettingsLayout from "@/Layouts/SettingsLayout";
import Purchase from "@/Components/Purchase";
import { useEffect, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import LaboratoryPurchaseTabs from "@/Components/Laboratory/LaboratoryPurchaseTabs";
import Card from "@/Components/Card";
import PatientTabContent from "@/Components/Laboratory/Tabs/PatientTabContent";
import OrderInfoTabContent from "@/Components/Laboratory/Tabs/OrderInfoTabContent";
import StoresTabContent from "@/Components/Laboratory/Tabs/StoresTabContent";
import PaymentTabContent from "@/Components/Laboratory/Tabs/PaymentTabContent";
import AddressTabContent from "@/Components/Laboratory/Tabs/AddressTabContent";
import DetailsTabContent from "@/Components/Laboratory/Tabs/DetailsTabContent";
import ResultsTabContent from "@/Components/Laboratory/Tabs/ResultsTabContent";
import InvoiceTabContent from "@/Components/Laboratory/Tabs/InvoiceTabContent";
import StatusTabContent from "@/Components/Laboratory/Tabs/StatusTabContent";
import OtpModal from "@/Components/OtpModal";
import LabResultsOtpSessionBadge from "@/Components/LabResultsOtpSessionBadge";
import { useLabResultsOtpSession } from "../Hooks/useLabResultsOtpSession";
export default function LaboratoryPurchase({
    laboratoryPurchase,
    confetti,
    latestSampleCollectionAt,
    latestResultsAt,
    hasSampleCollected,
    hasResultsAvailable
}) {
    const [activeTab, setActiveTab] = useState("paciente");
    const { url } = usePage();
    const [showOtpModal, setShowOtpModal] = useState(false);
    const pendingAfterOtpRef = useRef(null);

    const otpSession = useLabResultsOtpSession(laboratoryPurchase?.id);

    useEffect(() => {
        try {
            const query = url.includes("?") ? url.split("?")[1] : "";
            const params = new URLSearchParams(query);
            if (params.get("tab") === "facturas") {
                setActiveTab("facturas");
            }
        } catch {
            // ignore
        }
    }, [url]);

    // Determinar si hay resultados (automáticos o manuales)
    const hasAnyResults = hasResultsAvailable || !!laboratoryPurchase.results;

    const handleOtpSuccess = (payload) => {
        if (payload?.expires_in != null) {
            otpSession.armSession(payload.expires_in);
        } else {
            otpSession.refresh();
        }
        setShowOtpModal(false);
        const next = pendingAfterOtpRef.current;
        pendingAfterOtpRef.current = null;
        if (typeof next === "function") next();
    };

    const requireOtpThen = (fn) => {
        if (otpSession.verified) {
            fn?.();
            return true;
        }
        pendingAfterOtpRef.current = fn;
        setShowOtpModal(true);
        return false;
    };

    const handleTabChange = (tab) => {
        if (tab === "resultados" && !otpSession.verified) {
            requireOtpThen(() => setActiveTab("resultados"));
            return;
        }
        setActiveTab(tab);
    };

    useEffect(() => {
        if (laboratoryPurchase && !window.ga4PurchaseSent) {
            window.dataLayer = window.dataLayer || [];

            const totalValue = laboratoryPurchase.total_cents / 100;

            const items = laboratoryPurchase.laboratory_purchase_items?.map(
                (item, index) => ({
                    item_id: item.gda_id || `lab_${item.id}`,
                    item_name: item.name || "Laboratory Test",
                    price: item.price_cents ? item.price_cents / 100 : 0,
                    quantity: 1,
                    item_category: "Laboratory Tests",
                    item_brand: laboratoryPurchase.brand?.value || "laboratory",
                    index: index,
                })
            ) || [];

            window.dataLayer.push({
                event: "purchase",
                ecommerce: {
                    transaction_id: laboratoryPurchase.id.toString(),
                    value: totalValue,
                    currency: "MXN",
                    items: items
                }
            });

            window.ga4PurchaseSent = true;
        }
    }, [laboratoryPurchase]);

    return (
        <SettingsLayout title="Pedido de laboratorio">
            <Card className="space-y-6 p-6 lg:space-y-8 lg:p-8">
                {/* Header Section - Información de compra y agradecimiento */}
                <Purchase
                    purchase={laboratoryPurchase}
                    isLabPurchase={true}
                    requireOtpThen={requireOtpThen}
                />

                {/* Tabs Navigation */}
                <LaboratoryPurchaseTabs
                    activeTab={activeTab}
                    onTabChange={handleTabChange}
                    hasResults={hasAnyResults}
                    hasInvoice={!!laboratoryPurchase.invoice}
                />

                {/* Tab Content */}
                <div className="mt-6">
                    {activeTab === "paciente" && (
                        <PatientTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "orden" && (
                        <OrderInfoTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "sucursales" && (
                        <StoresTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "pago" && (
                        <PaymentTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "direccion" && (
                        <AddressTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "detalles" && (
                        <DetailsTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "resultados" && (
                        <ResultsTabContent
                            laboratoryPurchase={laboratoryPurchase}
                            latestResultsAt={latestResultsAt}
                            hasResultsAvailable={hasResultsAvailable}
                            hasManualResults={!!laboratoryPurchase.results}
                            requireOtpThen={requireOtpThen}
                        />
                    )}

                    {activeTab === "facturas" && (
                        <InvoiceTabContent purchase={laboratoryPurchase} />
                    )}

                    {activeTab === "estado" && (
                        <StatusTabContent
                            laboratoryPurchase={laboratoryPurchase}
                            latestSampleCollectionAt={latestSampleCollectionAt}
                            latestResultsAt={latestResultsAt}
                            hasSampleCollected={hasSampleCollected}
                            hasResultsAvailable={hasResultsAvailable}
                        />
                    )}
                </div>
            </Card>

            {showOtpModal && (
                <OtpModal
                    isOpen={showOtpModal}
                    purchaseId={laboratoryPurchase.id}
                    onSuccess={handleOtpSuccess}
                    onClose={() => {
                        pendingAfterOtpRef.current = null;
                        setShowOtpModal(false);
                    }}
                />
            )}

            <LabResultsOtpSessionBadge secondsLeft={otpSession.secondsLeft} />
        </SettingsLayout>
    );
}
