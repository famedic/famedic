import { useEffect, useMemo, useState } from "react";
import { usePage } from "@inertiajs/react";

const ENVIRONMENT_STYLES = {
	local: "bg-purple-700",
	testing: "bg-orange-500",
	staging: "bg-blue-700",
	default: "bg-red-700",
};

export default function EnvironmentBanner({ onVisibilityChange = null }) {
	const page = usePage();
	const appEnvRaw = page?.props?.appEnv;
	const normalizedEnv = typeof appEnvRaw === "string" ? appEnvRaw.toLowerCase() : "";
	const [isDismissed, setIsDismissed] = useState(false);

	const isVisible = useMemo(() => {
		return Boolean(normalizedEnv) && normalizedEnv !== "production" && !isDismissed;
	}, [normalizedEnv, isDismissed]);

	const backgroundClass =
		ENVIRONMENT_STYLES[normalizedEnv] ?? ENVIRONMENT_STYLES.default;

	useEffect(() => {
		setIsDismissed(false);
	}, [normalizedEnv]);

	useEffect(() => {
		onVisibilityChange?.(isVisible);
	}, [isVisible, onVisibilityChange]);

	if (!isVisible) {
		return null;
	}

	return (
		<div
			className={`fixed inset-x-0 top-0 z-[9999] h-10 ${backgroundClass} text-white shadow-lg animate-in fade-in slide-in-from-top-1 duration-300`}
			role="status"
			aria-live="polite"
		>
			<div className="mx-auto flex h-full max-w-screen-2xl items-center justify-center px-3">
				<span className="text-center text-xs font-black tracking-[0.2em] sm:text-sm">
					ENTORNO: {normalizedEnv.toUpperCase()}
				</span>
				<button
					type="button"
					onClick={() => setIsDismissed(true)}
					className="absolute right-3 rounded px-2 py-1 text-xs font-bold text-white/90 hover:bg-white/20 hover:text-white focus:outline-none focus:ring-2 focus:ring-white/70"
					aria-label="Cerrar banner de entorno"
				>
					CERRAR
				</button>
			</div>
		</div>
	);
}
