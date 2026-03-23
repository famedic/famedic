// Components/WelcomeMessage.jsx
import { useEffect, useState } from "react";
import { SunIcon, MoonIcon, CloudIcon } from "@heroicons/react/24/outline";
import { SparklesIcon } from "@heroicons/react/24/solid";
import clsx from "clsx";
import UserDashboardStats from "./UserDashboardStats";

// Paleta de colores sobrios para el gradiente
const colors = {
    primary: "#1e2a3e",
    primaryLight: "#2d3a4e",
    secondary: "#4a5b6e",
    accent: "#2c3e50",
};

export default function WelcomeMessage({ user, stats, recentResults, className }) {
    const [greeting, setGreeting] = useState("");
    const [icon, setIcon] = useState(null);
    const [message, setMessage] = useState("");
    const [currentTime, setCurrentTime] = useState(new Date());

    useEffect(() => {
        const hour = currentTime.getHours();
        
        if (hour >= 5 && hour < 12) {
            setGreeting("Buenos días");
            setIcon(SunIcon);
            setMessage("🌅 Que tengas un día productivo");
        } else if (hour >= 12 && hour < 18) {
            setGreeting("Buenas tardes");
            setIcon(SunIcon);
            setMessage("☀️ Continúa cuidando tu bienestar");
        } else if (hour >= 18 && hour < 22) {
            setGreeting("Buenas noches");
            setIcon(CloudIcon);
            setMessage("🌙 Un momento para descansar");
        } else {
            setGreeting("Buenas noches");
            setIcon(MoonIcon);
            setMessage("✨ Descansa, mañana te esperamos");
        }
        
        const interval = setInterval(() => {
            setCurrentTime(new Date());
        }, 60000);
        
        return () => clearInterval(interval);
    }, [currentTime]);

    const GreetingIcon = icon;
    const firstName = user?.full_name?.split(" ")[0] || user?.name?.split(" ")[0] || "Usuario";

    return (
        <div className="space-y-3">
            {/* Mensaje de bienvenida con gradiente sobrio */}
            <div className={clsx(
                "relative overflow-hidden rounded-xl p-4 shadow-md",
                className
            )} style={{ background: `linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%)` }}>
                <div className="absolute inset-0 bg-black opacity-10" />
                <div className="relative flex items-center gap-3">
                    <div className="rounded-full bg-white/20 p-2">
                        {GreetingIcon && (
                            <GreetingIcon className="h-5 w-5 text-white md:h-6 md:w-6" />
                        )}
                    </div>
                    <div className="flex-1">
                        <h2 className="text-base font-bold text-white md:text-lg">
                            {greeting}, {firstName}
                        </h2>
                        <p className="text-xs text-white/80">
                            {message}
                        </p>
                    </div>
                    <SparklesIcon className="h-4 w-4 text-white/40" />
                </div>
            </div>
            
            {/* Estadísticas compactas */}
            {stats && (
                <UserDashboardStats 
                    user={user} 
                    stats={stats} 
                    recentResults={recentResults}
                />
            )}
        </div>
    );
}