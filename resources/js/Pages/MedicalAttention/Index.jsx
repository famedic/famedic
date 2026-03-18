// resources/js/Pages/MedicalAttention/Index.jsx
import FamedicLayout from "@/Layouts/FamedicLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { useState, useEffect } from "react";
import SubscribeHero from "./components/SubscribeHero";
import SubscriptionHero from "./components/SubscriptionHero";
import SubscribeModal from "./components/SubscribeModal";
import SubscriptionDetails from "./components/SubscriptionDetails";

export default function MedicalAttention({
    hasOdessaAfiliateAccount,
    medicalAttentionSubscriptionIsActive,
    formattedMedicalAttentionSubscriptionExpiresAt,
    medicalAttentionIdentifier,
    familyAccounts,
    paymentMethods,
    formattedPrice,
}) {
    let [isOpen, setIsOpen] = useState(false);

    // Determinar el tipo de plan para pasarlo a los hijos
    const planType = medicalAttentionSubscriptionIsActive 
        ? (hasOdessaAfiliateAccount ? 'premium' : 'basico')
        : 'ninguno';

    // Teléfonos según el plan
    const phoneNumber = hasOdessaAfiliateAccount ? '5541697768' : '5594540058';
    const formattedPhone = hasOdessaAfiliateAccount ? '55 4169 7768' : '55 9454 0058';

    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('🏥 COMPONENTE PRINCIPAL: MedicalAttention');
            console.log('📥 Props recibidas:');
            console.log('  - hasOdessaAfiliateAccount:', hasOdessaAfiliateAccount);
            console.log('  - medicalAttentionSubscriptionIsActive:', medicalAttentionSubscriptionIsActive);
            console.log('  - formattedMedicalAttentionSubscriptionExpiresAt:', formattedMedicalAttentionSubscriptionExpiresAt);
            console.log('  - medicalAttentionIdentifier:', medicalAttentionIdentifier);
            console.log('  - familyAccounts:', familyAccounts);
            console.log('  - paymentMethods:', paymentMethods);
            console.log('  - formattedPrice:', formattedPrice);
            console.log('  - 📞 Teléfono asignado:', formattedPhone);
            
            // Determinar tipo de membresía/plan
            let membershipType = '❌ Sin membresía';
            let planDetails = {};
            
            if (medicalAttentionSubscriptionIsActive) {
                if (hasOdessaAfiliateAccount) {
                    membershipType = '⭐ Membresía Premium (con Odessa)';
                    planDetails = {
                        tipo: 'Premium',
                        telefono: formattedPhone,
                        beneficios: ['Médico en casa', 'Ambulancia', 'Reembolso medicamentos'],
                        vigencia: formattedMedicalAttentionSubscriptionExpiresAt
                    };
                } else {
                    membershipType = '🔵 Membresía Básica';
                    planDetails = {
                        tipo: 'Básico',
                        telefono: formattedPhone,
                        beneficios: ['Solo telemedicina'],
                        vigencia: formattedMedicalAttentionSubscriptionExpiresAt
                    };
                }
            } else if (formattedMedicalAttentionSubscriptionExpiresAt) {
                membershipType = '⏳ Período de prueba';
                planDetails = {
                    tipo: 'Prueba',
                    vigencia: formattedMedicalAttentionSubscriptionExpiresAt
                };
            }
            
            console.log('🎯 Análisis del plan:');
            console.log('  - Tipo de membresía:', membershipType);
            console.log('  - Detalles del plan:', planDetails);
            console.log('  - Estado del diálogo:', isOpen ? 'Abierto' : 'Cerrado');
            console.groupEnd();
        }
    }, [
        hasOdessaAfiliateAccount,
        medicalAttentionSubscriptionIsActive,
        formattedMedicalAttentionSubscriptionExpiresAt,
        medicalAttentionIdentifier,
        familyAccounts,
        paymentMethods,
        formattedPrice,
        isOpen
    ]);

    return (
        <FamedicLayout title="Atención médica">
            <GradientHeading>
                Atención médica
                {medicalAttentionSubscriptionIsActive && (
                    <span className="ml-2 inline-block">
                        {hasOdessaAfiliateAccount ? '⭐' : '🔵'}
                    </span>
                )}
            </GradientHeading>

            {medicalAttentionSubscriptionIsActive ? (
                <>
                    <SubscriptionHero
                        formattedMedicalAttentionSubscriptionExpiresAt={
                            formattedMedicalAttentionSubscriptionExpiresAt
                        }
                        medicalAttentionIdentifier={medicalAttentionIdentifier}
                        familyAccounts={familyAccounts}
                        planType={planType}
                        hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                        phoneNumber={phoneNumber}
                        formattedPhone={formattedPhone}
                    />
                    <SubscriptionDetails 
                        planType={planType}
                        hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                    />
                </>
            ) : (
                <>
                    <SubscribeHero
                        setIsOpen={setIsOpen}
                        formattedMedicalAttentionSubscriptionExpiresAt={
                            formattedMedicalAttentionSubscriptionExpiresAt
                        }
                        formattedPrice={formattedPrice}
                        paymentMethods={paymentMethods}
                    />
                    <SubscribeModal
                        hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                        isOpen={isOpen}
                        setIsOpen={setIsOpen}
                        paymentMethods={paymentMethods}
                        formattedPrice={formattedPrice}
                        formattedMedicalAttentionSubscriptionExpiresAt={
                            formattedMedicalAttentionSubscriptionExpiresAt
                        }
                    />
                </>
            )}
        </FamedicLayout>
    );
}