import { useEffect, useRef } from "react";
import clsx from "clsx";

/**
 * Barra fija Continuar/Volver alineada a la columna del formulario (misma rejilla que CheckoutLayout).
 * Sin medición JS: evita que en producción no se renderice (return null) si falla el ref/layout.
 */
export default function CheckoutWizardFloatingFooter({ children, className }) {
	const footerRef = useRef(null);

	useEffect(() => {
		const footer = footerRef.current;
		if (!footer) return undefined;

		const root = document.documentElement;
		const syncHeight = () => {
			root.style.setProperty(
				"--checkout-floating-footer-height",
				`${footer.getBoundingClientRect().height}px`,
			);
		};
		const observer =
			typeof ResizeObserver === "undefined"
				? null
				: new ResizeObserver(syncHeight);

		root.classList.add("has-checkout-floating-footer");
		syncHeight();
		observer?.observe(footer);
		window.addEventListener("resize", syncHeight);

		return () => {
			observer?.disconnect();
			window.removeEventListener("resize", syncHeight);
			root.classList.remove("has-checkout-floating-footer");
			root.style.removeProperty("--checkout-floating-footer-height");
		};
	}, []);

	return (
		<div
			ref={footerRef}
			className={clsx(
				"pointer-events-none fixed inset-x-0 bottom-0 z-40",
				className,
			)}
		>
			<div className="pointer-events-auto mx-auto grid max-w-[100rem] grid-cols-1 gap-8 px-4 lg:grid-cols-5">
				<div
					className={clsx(
						"border-t border-zinc-200/90 bg-white/95 py-3 pr-20 shadow-[0_-8px_24px_-8px_rgba(0,0,0,0.12)] backdrop-blur-sm sm:pr-4",
						"supports-[backdrop-filter]:bg-white/85",
						"dark:border-slate-700 dark:bg-slate-950/95 dark:shadow-[0_-8px_24px_-8px_rgba(0,0,0,0.45)]",
						"lg:col-span-3",
					)}
				>
					{children}
				</div>
			</div>
		</div>
	);
}
