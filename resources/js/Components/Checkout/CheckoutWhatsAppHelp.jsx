import { useEffect, useRef, useState } from "react";
import clsx from "clsx";
import {
    QuestionMarkCircleIcon,
    CreditCardIcon,
    BeakerIcon,
    ChatBubbleLeftRightIcon,
} from "@heroicons/react/24/outline";

const WHATSAPP_E164 = "528128601893";

const HELP_TOPICS = [
    {
        label: "¿Tienes dudas?",
        message:
            "Hola, tengo dudas sobre mi compra en el checkout de laboratorio.",
        Icon: QuestionMarkCircleIcon,
    },
    {
        label: "Problemas de pago",
        message:
            "Hola, tengo problemas con el pago de mi compra en laboratorio.",
        Icon: CreditCardIcon,
    },
    {
        label: "Duda con los estudios",
        message:
            "Hola, tengo una duda con los estudios de laboratorio que quiero comprar.",
        Icon: BeakerIcon,
    },
    {
        label: "Atención a clientes",
        message: "Hola, necesito atención a clientes.",
        Icon: ChatBubbleLeftRightIcon,
    },
];

const DEFAULT_MESSAGE =
    "Hola, necesito ayuda con mi compra en el checkout de laboratorio.";

function openWhatsApp(message) {
    const url = `https://wa.me/${WHATSAPP_E164}?text=${encodeURIComponent(message)}`;
    window.open(url, "_blank", "noopener,noreferrer");
}

function WhatsAppIcon({ className }) {
    return (
        <svg
            className={className}
            viewBox="0 0 256 259"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden
        >
            <path
                d="m67.663 221.823 4.185 2.093c17.44 10.463 36.971 15.346 56.503 15.346 61.385 0 111.609-50.224 111.609-111.609 0-29.297-11.859-57.897-32.785-78.824-20.927-20.927-48.83-32.785-78.824-32.785-61.385 0-111.61 50.224-110.912 112.307 0 20.926 6.278 41.156 16.741 58.594l2.79 4.186-11.16 41.156 41.853-10.464Z"
                fill="#fff"
            />
            <path
                d="M219.033 37.668C195.316 13.254 162.531 0 129.048 0 57.898 0 .698 57.897 1.395 128.35c0 22.322 6.278 43.947 16.742 63.478L0 258.096l67.663-17.439c18.834 10.464 39.76 15.347 60.688 15.347 70.453 0 127.653-57.898 127.653-128.35 0-34.181-13.254-66.269-36.97-89.986ZM129.048 234.38c-18.834 0-37.668-4.882-53.712-14.648l-4.185-2.093-40.458 10.463 10.463-39.76-2.79-4.186C7.673 134.63 22.322 69.058 72.546 38.365c50.224-30.692 115.097-16.043 145.79 34.181 30.692 50.224 16.043 115.097-34.18 145.79-16.045 10.463-35.576 16.043-55.108 16.043Zm61.385-77.428-7.673-3.488s-11.16-4.883-18.136-8.371c-.698 0-1.395-.698-2.093-.698-2.093 0-3.488.698-4.883 1.396 0 0-.697.697-10.463 11.858-.698 1.395-2.093 2.093-3.488 2.093h-.698c-.697 0-2.092-.698-2.79-1.395l-3.488-1.395c-7.673-3.488-14.648-7.674-20.229-13.254-1.395-1.395-3.488-2.79-4.883-4.185-4.883-4.883-9.766-10.464-13.253-16.742l-.698-1.395c-.697-.698-.697-1.395-1.395-2.79 0-1.395 0-2.79.698-3.488 0 0 2.79-3.488 4.882-5.58 1.396-1.396 2.093-3.488 3.488-4.883 1.395-2.093 2.093-4.883 1.395-6.976-.697-3.488-9.068-22.322-11.16-26.507-1.396-2.093-2.79-2.79-4.883-3.488H83.01c-1.396 0-2.79.698-4.186.698l-.698.697c-1.395.698-2.79 2.093-4.185 2.79-1.395 1.396-2.093 2.79-3.488 4.186-4.883 6.278-7.673 13.951-7.673 21.624 0 5.58 1.395 11.161 3.488 16.044l.698 2.093c6.278 13.253 14.648 25.112 25.81 35.575l2.79 2.79c2.092 2.093 4.185 3.488 5.58 5.58 14.649 12.557 31.39 21.625 50.224 26.508 2.093.697 4.883.697 6.976 1.395h6.975c3.488 0 7.673-1.395 10.464-2.79 2.092-1.395 3.487-1.395 4.882-2.79l1.396-1.396c1.395-1.395 2.79-2.092 4.185-3.487 1.395-1.395 2.79-2.79 3.488-4.186 1.395-2.79 2.092-6.278 2.79-9.765v-4.883s-.698-.698-2.093-1.395Z"
                fill="#fff"
            />
        </svg>
    );
}

export default function CheckoutWhatsAppHelp() {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        const handlePointerDown = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener("mousedown", handlePointerDown);
        return () => document.removeEventListener("mousedown", handlePointerDown);
    }, [open]);

    const handleTopicClick = (message) => {
        setOpen(false);
        openWhatsApp(message);
    };

    return (
        <div
            ref={rootRef}
            className="pointer-events-none fixed bottom-4 right-4 z-[60] flex flex-col items-end gap-3 sm:bottom-6 sm:right-6"
            aria-live="polite"
        >
            {open && (
                <div
                    className="pointer-events-auto w-[min(100vw-2rem,20rem)] overflow-hidden rounded-xl border border-zinc-200/80 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
                    role="dialog"
                    aria-label="Ayuda por WhatsApp"
                >
                    <div className="bg-[#25D366] px-4 py-3 text-center text-sm font-semibold text-white">
                        ¿Necesitas ayuda? 👋
                    </div>
                    <div className="px-4 py-3">
                        <p className="text-center text-xs text-zinc-600 dark:text-slate-400">
                            Estamos aquí para ayudarte. Elige un tema o
                            escríbenos:
                        </p>
                        <ul className="mt-3 divide-y divide-zinc-100 dark:divide-slate-800">
                            {HELP_TOPICS.map(({ label, message, Icon }) => (
                                <li key={label}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handleTopicClick(message)
                                        }
                                        className="flex w-full items-center gap-3 py-3 text-left text-sm text-zinc-700 transition-colors hover:bg-zinc-50 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                    >
                                        <Icon className="size-5 shrink-0 text-sky-600 dark:text-sky-400" />
                                        <span>{label}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            onClick={() => handleTopicClick(DEFAULT_MESSAGE)}
                            className="mt-2 w-full py-2 text-center text-sm font-medium text-[#25D366] hover:underline"
                        >
                            Escríbenos por WhatsApp
                        </button>
                    </div>
                </div>
            )}

            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className={clsx(
                    "pointer-events-auto relative flex size-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg ring-2 ring-white transition hover:bg-[#20bd5a] focus:outline-none focus-visible:ring-4 focus-visible:ring-[#25D366]/40 dark:ring-slate-900",
                    open && "ring-[#25D366]/30",
                )}
                aria-expanded={open}
                aria-label={
                    open
                        ? "Cerrar menú de ayuda por WhatsApp"
                        : "Abrir menú de ayuda por WhatsApp"
                }
            >
                <span className="absolute -right-0.5 -top-0.5 flex size-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-900">
                    1
                </span>
                <WhatsAppIcon className="size-8" />
            </button>
        </div>
    );
}
