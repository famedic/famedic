// Pages/Home.jsx
import FamedicLayout from "@/Layouts/FamedicLayout";
import Hero from "@/Pages/Home/Hero";
import CTA from "@/Pages/Home/CTA";
import QuickLinks from "@/Pages/Home/QuickLinks";
import { usePage } from "@inertiajs/react";

export default function Home() {
    const { auth, invitationUrl, userStats, recentResults } = usePage().props;

    return (
        <FamedicLayout title="Bienvenido">
            <Hero 
                invitationUrl={invitationUrl} 
                auth={auth} 
                userStats={userStats}
                recentResults={recentResults}
            />
            <CTA />
            <QuickLinks />
        </FamedicLayout>
    );
}