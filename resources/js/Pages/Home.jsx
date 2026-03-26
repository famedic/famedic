// Pages/Home.jsx
import FamedicLayout from "@/Layouts/FamedicLayout";
import Hero from "@/Pages/Home/Hero";
import CTA from "@/Pages/Home/CTA";
import QuickLinks from "@/Pages/Home/QuickLinks";
import { usePage } from "@inertiajs/react";
import { useEffect } from "react";

export default function Home() {
    const page = usePage();
    const { auth, invitationUrl, userStats, recentResults } = page.props;

    useEffect(() => {
        if (import.meta.env.DEV) {
            // Debug temporal: validar props en /home (quitar cuando ya no haga falta)
            console.log("[Home] usePage().props", page.props);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- log único al montar
    }, []);

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