// Components/WelcomeMessage.jsx
import { useEffect, useState } from "react";
import { SunIcon, MoonIcon, CloudIcon } from "@heroicons/react/24/outline";
import { SparklesIcon } from "@heroicons/react/24/solid";
import clsx from "clsx";

export default function WelcomeMessage({ user, className }) {
    const [greeting, setGreeting] = useState("");
    const [icon, setIcon] = useState(null);
    const [timeColor, setTimeColor] = useState("");
    const [message, setMessage] = useState("");

    useEffect(() => {
        const hour = new Date().getHours();
        
        // Determinar el saludo según la hora
        if (hour >= 5 && hour < 12) {
            setGreeting("Buenos días");
            setIcon(SunIcon);
            setTimeColor("from-amber-400 to-orange-500");
            setMessage("Que tengas un día lleno de energía y salud");
        } else if (hour >= 12 && hour < 18) {
            setGreeting("Buenas tardes");
            setIcon(SunIcon);
            setTimeColor("from-yellow-400 to-amber-500");
            setMessage("Sigue cuidando tu bienestar esta tarde");
        } else if (hour >= 18 && hour < 22) {
            setGreeting("Buenas noches");
            setIcon(CloudIcon);
            setTimeColor("from-indigo-400 to-purple-500");
            setMessage("Relájate y prepárate para descansar");
        } else {
            setGreeting("Buenas noches");
            setIcon(MoonIcon);
            setTimeColor("from-blue-400 to-indigo-600");
            setMessage("Descansa bien, mañana te esperamos");
        }
    }, []);

    const GreetingIcon = icon;

    // Obtener el primer nombre del usuario
    const getFirstName = () => {
        if (!user) return "Usuario";
        if (user.name && typeof user.name === 'string') {
            return user.name.split(" ")[0];
        }
        if (typeof user === 'string') return user.split(" ")[0];
        return "Usuario";
    };

    const firstName = getFirstName();

    return (
        <div className={clsx(
            "relative overflow-hidden rounded-2xl bg-gradient-to-r p-6 shadow-lg transition-all duration-500 hover:shadow-xl",
            timeColor,
            className
        )}>
            {/* Fondo decorativo */}
            <div className="absolute inset-0 bg-black opacity-10" />
            
            {/* Efecto de brillo */}
            <div className="absolute -inset-1 bg-gradient-to-r from-transparent via-white/20 to-transparent blur-xl" />
            
            <div className="relative flex items-center gap-4">
                {/* Icono animado */}
                <div className="animate-pulse rounded-full bg-white/20 p-3 backdrop-blur-sm">
                    {GreetingIcon && (
                        <GreetingIcon className="h-8 w-8 text-white md:h-10 md:w-10" />
                    )}
                </div>
                
                {/* Contenido del mensaje */}
                <div className="flex-1 text-left">
                    <div className="flex flex-col gap-1">
                        <h2 className="text-2xl font-bold text-white md:text-3xl">
                            {greeting}, {firstName}!
                        </h2>
                        <p className="text-sm text-white/90 md:text-base">
                            {message}
                        </p>
                        <p className="mt-1 text-xs text-white/70">
                            Bienvenido a Famedic, tu salud es nuestra prioridad
                        </p>
                    </div>
                </div>
                
                {/* Detalle decorativo */}
                <SparklesIcon className="absolute bottom-2 right-2 h-6 w-6 text-white/20 md:h-8 md:w-8" />
            </div>
        </div>
    );
}