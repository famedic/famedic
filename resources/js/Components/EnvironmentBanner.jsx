import { useMemo } from "react";
import { usePage } from "@inertiajs/react";

const ENVIRONMENT_DOT_STYLES = {
	local: "bg-purple-500",
	testing: "bg-orange-500",
	staging: "bg-blue-500",
	default: "bg-red-500",
};

export default function EnvironmentIndicator() {
	const page = usePage();
	const appEnvRaw = page?.props?.appEnv;
	const normalizedEnv = typeof appEnvRaw === "string" ? appEnvRaw.toLowerCase() : "";

	const isVisible = useMemo(() => {
		return Boolean(normalizedEnv) && normalizedEnv !== "production";
	}, [normalizedEnv]);

	const dotClass =
		ENVIRONMENT_DOT_STYLES[normalizedEnv] ?? ENVIRONMENT_DOT_STYLES.default;

	if (!isVisible) {
		return null;
	}

	const label = `Entorno: ${normalizedEnv}`;

	return (
		<span
			className="group relative inline-flex shrink-0 items-center"
			title={label}
		>
			<span
				className={`size-2 rounded-full ${dotClass} ring-1 ring-black/10 dark:ring-white/25`}
				aria-hidden="true"
			/>
			<span
				role="tooltip"
				className="pointer-events-none absolute left-1/2 top-full z-[100] mt-1.5 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 dark:bg-zinc-100 dark:text-zinc-900"
			>
				{label}
			</span>
			<span className="sr-only">{label}</span>
		</span>
	);
}
