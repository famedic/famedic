import { useState } from 'react';
import { Button } from '@/Components/Catalyst/button';
import { Modal } from '@/Components/Catalyst/modal';
import { Text } from '@/Components/Catalyst/text';

export default function ViewResultsButton({ entity, entityType }) {
    const [isOpen, setIsOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [pdfError, setPdfError] = useState(false);

    const hasResults = entity.results_pdf_base64 != null;
    const resultsDate = entity.results_received_at;

    const handleViewResults = async () => {
        if (!hasResults) return;
        
        setIsLoading(true);
        setPdfError(false);
        setIsOpen(true);
        setIsLoading(false);
    };

    const loadPdfInIframe = (base64) => {
        if (!base64) return null;
        
        try {
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            const blob = new Blob([bytes], { type: "application/pdf" });
            return URL.createObjectURL(blob);
        } catch (error) {
            console.error("Error al cargar PDF:", error);
            setPdfError(true);
            return null;
        }
    };

    const pdfUrl = hasResults ? loadPdfInIframe(entity.results_pdf_base64) : null;

    return (
        <>
            <Button
                onClick={handleViewResults}
                disabled={!hasResults}
                className="flex items-center gap-2"
                plain={!hasResults}
            >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                {hasResults ? 'Ver Resultados' : 'Resultados No Disponibles'}
            </Button>

            <Modal open={isOpen} onClose={() => setIsOpen(false)} size="xl">
                <div className="p-6">
                    <div className="flex justify-between items-center mb-4">
                        <Text className="text-lg font-semibold">
                            Resultados de Laboratorio
                        </Text>
                        <Button onClick={() => setIsOpen(false)} plain>
                            ✕
                        </Button>
                    </div>

                    {hasResults ? (
                        <div className="space-y-4">
                            <div className="flex justify-between items-center text-sm text-gray-600">
                                <span>Recibido: {new Date(resultsDate).toLocaleDateString('es-MX')}</span>
                                <span>Referencia: {entity.gda_acuse}</span>
                            </div>

                            {pdfError ? (
                                <div className="text-center py-8 text-red-600">
                                    Error al cargar el PDF de resultados
                                </div>
                            ) : (
                                <div className="w-full h-[600px] border rounded-lg overflow-hidden">
                                    <iframe
                                        src={pdfUrl}
                                        className="w-full h-full"
                                        title="Resultados de Laboratorio"
                                    />
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="text-center py-8 text-gray-500">
                            Los resultados aún no están disponibles
                        </div>
                    )}
                </div>
            </Modal>
        </>
    );
}