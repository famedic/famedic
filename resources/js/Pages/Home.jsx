// Pages/Home.jsx
import FamedicLayout from "@/Layouts/FamedicLayout";
import Hero from "@/Pages/Home/Hero";
import CTA from "@/Pages/Home/CTA";
import QuickLinks from "@/Pages/Home/QuickLinks";
import BenavidesBenefitBanner from "@/Components/Benefits/BenavidesBenefitBanner";
import BenavidesBenefitModal from "@/Components/Benefits/BenavidesBenefitModal";
import { usePage } from "@inertiajs/react";

export default function Home() {
    const page = usePage();
    const { auth, invitationUrl, userStats, recentResults, benavidesBenefit } = page.props;

    return (
        <FamedicLayout title="Bienvenido">
            <BenavidesBenefitModal benefit={benavidesBenefit} />
            <Hero 
                invitationUrl={invitationUrl} 
                auth={auth} 
                userStats={userStats}
                recentResults={recentResults}
            />
            <BenavidesBenefitBanner benefit={benavidesBenefit} />
            <CTA />
            <QuickLinks />
        </FamedicLayout>
    );
}
