import { ReceiptPercentIcon, DocumentTextIcon, ClockIcon, ArrowDownTrayIcon } from "@heroicons/react/24/outline";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Anchor } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import RequestInvoiceModal from "@/Components/RequestInvoiceModal";
import { useState } from "react";
import { usePage } from "@inertiajs/react";

export default function InvoiceTabContent({ purchase }) {
    const [showModal, setShowModal] = useState(false);
    const { daysLeftToRequestInvoice } = usePage().props;
    
    const canRequestInvoice = !purchase.invoice && daysLeftToRequestInvoice > 0 && daysLeftToRequestInvoice <= 7;
    const hasInvoiceRequest = purchase.invoice_request;
    const hasInvoice = purchase.invoice;
    
    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex items-center gap-3 mb-4">
                    <ReceiptPercentIcon className="size-6 text-famedic-500" />
                    <Text className="text-lg font-semibold">Facturación</Text>
                </div>
                
                {/* Banner de advertencia de tiempo limitado */}
                {canRequestInvoice && !hasInvoiceRequest && (
                    <div className="bg-amber-50 dark:bg-amber-950/20 rounded-lg p-4 mb-6">
                        <div className="flex items-center gap-3 mb-2">
                            <ClockIcon className="size-5 text-amber-600 flex-shrink-0" />
                            <Text className="font-medium text-amber-700 dark:text-amber-300">
                                ⚠️ ¡Tiempo limitado para solicitar factura!
                            </Text>
                        </div>
                        <Text className="text-sm text-amber-600 dark:text-amber-400">
                            Solo tienes{" "}
                            <Strong>
                                {daysLeftToRequestInvoice} día
                                {daysLeftToRequestInvoice > 1 ? "s" : ""} restante
                                {daysLeftToRequestInvoice > 1 ? "s" : ""}
                            </Strong>{" "}
                            para solicitar la factura de esta orden. Las facturas solo
                            pueden solicitarse hasta el último día del mes.
                        </Text>
                    </div>
                )}
                
                {/* Caso 1: Factura ya generada */}
                {hasInvoice && (
                    <div className="bg-green-50 dark:bg-green-950/20 rounded-lg p-4">
                        <div className="flex items-center gap-2 mb-3">
                            <DocumentTextIcon className="size-5 text-green-600" />
                            <Text className="font-medium text-green-700 dark:text-green-300">
                                Factura generada
                            </Text>
                        </div>
                        <Anchor
                            href={route("invoice", { invoice: purchase.invoice })}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Button outline className="w-full sm:w-auto">
                                <ArrowDownTrayIcon className="size-4" />
                                Descargar factura
                            </Button>
                        </Anchor>
                    </div>
                )}
                
                {/* Caso 2: Factura solicitada (en proceso) */}
                {!hasInvoice && hasInvoiceRequest && (
                    <div className="bg-blue-50 dark:bg-blue-950/20 rounded-lg p-4">
                        <div className="flex items-center gap-2 mb-2">
                            <ClockIcon className="size-5 text-blue-600" />
                            <Text className="font-medium text-blue-700 dark:text-blue-300">
                                Factura solicitada
                            </Text>
                        </div>
                        <Text className="text-sm text-blue-600 dark:text-blue-400">
                            Tu solicitud de factura está siendo procesada. En breve recibirás tu factura por correo electrónico.
                        </Text>
                    </div>
                )}
                
                {/* Caso 3: Puede solicitar factura */}
                {!hasInvoice && !hasInvoiceRequest && canRequestInvoice && (
                    <div>
                        <Button 
                            onClick={() => setShowModal(true)} 
                            className="w-full sm:w-auto"
                        >
                            <ReceiptPercentIcon className="size-4" />
                            Solicitar factura
                        </Button>
                    </div>
                )}
                
                {/* Caso 4: No se puede solicitar factura (tiempo expirado) */}
                {!hasInvoice && !hasInvoiceRequest && daysLeftToRequestInvoice > 0 && daysLeftToRequestInvoice > 7 && (
                    <div className="text-center py-6">
                        <ReceiptPercentIcon className="size-12 text-gray-300 mx-auto mb-3" />
                        <Text className="text-gray-500">No se solicitó factura para esta orden</Text>
                        <Text className="text-sm text-gray-400 mt-1">
                            El período para solicitar factura ya expiró (solo se puede solicitar hasta 7 días después de la compra)
                        </Text>
                    </div>
                )}
                
                {/* Caso 5: Días restantes negativos o sin información */}
                {!hasInvoice && !hasInvoiceRequest && (!daysLeftToRequestInvoice || daysLeftToRequestInvoice <= 0) && (
                    <div className="text-center py-6">
                        <ReceiptPercentIcon className="size-12 text-gray-300 mx-auto mb-3" />
                        <Text className="text-gray-500">No se solicitó factura para esta orden</Text>
                        <Text className="text-sm text-gray-400 mt-1">
                            El período para solicitar factura ya expiró
                        </Text>
                    </div>
                )}
            </Card>
            
            {/* Modal para solicitar factura */}
            <RequestInvoiceModal
                purchase={purchase}
                isOpen={showModal}
                storeRoute={route("laboratory-purchases.invoice-request", {
                    laboratory_purchase: purchase
                })}
                close={() => setShowModal(false)}
            />
        </div>
    );
}