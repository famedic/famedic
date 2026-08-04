// resources/js/Pages/MedicalAttention/Index.jsx
import FamedicLayout from "@/Layouts/FamedicLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import SubscribeHero from "./components/SubscribeHero";
import SubscriptionHero from "./components/SubscriptionHero";
import SubscriptionDetails from "./components/SubscriptionDetails";

export default function MedicalAttention({
    hasOdessaAfiliateAccount,
    medicalAttentionSubscriptionIsActive,
    formattedMedicalAttentionSubscriptionExpiresAt,
    medicalAttentionIdentifier,
    familyAccounts,
    formattedPrice,
}) {
    const planType = medicalAttentionSubscriptionIsActive
        ? hasOdessaAfiliateAccount
            ? "premium"
            : "basico"
        : "ninguno";

    const phoneNumber = hasOdessaAfiliateAccount ? "5541697768" : "5594540058";
    const formattedPhone = hasOdessaAfiliateAccount
        ? "55 4169 7768"
        : "55 9454 0058";

    return (
        <FamedicLayout title="Atención médica">
            <GradientHeading>
                Atención médica
                {medicalAttentionSubscriptionIsActive && (
                    <span className="ml-2 inline-block">
                        {hasOdessaAfiliateAccount ? "⭐" : "🔵"}
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
                <SubscribeHero
                    formattedMedicalAttentionSubscriptionExpiresAt={
                        formattedMedicalAttentionSubscriptionExpiresAt
                    }
                    formattedPrice={formattedPrice}
                />
            )}
        </FamedicLayout>
    );
}
