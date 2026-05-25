import { useLayoutEffect, useState } from "react";
import clsx from "clsx";

/**
 * Barra de acciones fija al fondo del viewport, alineada solo con la columna del formulario.
 */
export default function CheckoutWizardFloatingFooter({
    children,
    anchorRef,
    className,
}) {
    const [position, setPosition] = useState(null);

    useLayoutEffect(() => {
        const anchor = anchorRef?.current;
        if (!anchor) return undefined;

        const updatePosition = () => {
            const { left, width } = anchor.getBoundingClientRect();
            setPosition({ left, width });
        };

        updatePosition();

        const resizeObserver = new ResizeObserver(updatePosition);
        resizeObserver.observe(anchor);

        window.addEventListener("resize", updatePosition);
        window.addEventListener("scroll", updatePosition, { passive: true });

        return () => {
            resizeObserver.disconnect();
            window.removeEventListener("resize", updatePosition);
            window.removeEventListener("scroll", updatePosition);
        };
    }, [anchorRef]);

    if (!position) {
        return null;
    }

    return (
        <div
            className={clsx(
                "fixed bottom-0 z-30 border-t border-zinc-200/90 bg-white/95 px-4 py-3 pr-20 shadow-[0_-8px_24px_-8px_rgba(0,0,0,0.12)] backdrop-blur-sm sm:pr-4",
                "supports-[backdrop-filter]:bg-white/85",
                "dark:border-slate-700 dark:bg-slate-950/95 dark:shadow-[0_-8px_24px_-8px_rgba(0,0,0,0.45)]",
                className,
            )}
            style={{
                left: position.left,
                width: position.width,
            }}
        >
            {children}
        </div>
    );
}
