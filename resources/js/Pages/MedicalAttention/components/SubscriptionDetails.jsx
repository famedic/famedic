// resources/js/Pages/MedicalAttention/components/SubscriptionDetails.jsx
import { useEffect } from "react";
import CoverageDetails from "./CoverageDetails";

export default function SubscriptionDetails({ planType, hasOdessaAfiliateAccount }) {
    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('📄 COMPONENTE: SubscriptionDetails');
            console.log('📥 Props:', { planType, hasOdessaAfiliateAccount });
            console.log('📌 Renderizando detalles de suscripción');
            console.groupEnd();
        }
    }, [planType, hasOdessaAfiliateAccount]);

    return (
        <div className="lg:!-mt-24 lg:px-12">
            <div className="mt-12 grid grid-cols-2 gap-6">
                <CoverageDetails />
            </div>
        </div>
    );
}