import clsx from "clsx";

/**
 * Barra fija Continuar/Volver alineada a la columna del formulario (misma rejilla que CheckoutLayout).
 * Sin medición JS: evita que en producción no se renderice (return null) si falla el ref/layout.
 */
export default function CheckoutWizardFloatingFooter({ children, className }) {
    return (
        <div
            className={clsx(
                "pointer-events-none fixed inset-x-0 bottom-0 z-30",
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
