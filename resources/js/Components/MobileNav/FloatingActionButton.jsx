import { PlusIcon } from "@heroicons/react/24/solid";
import clsx from "clsx";

/**
 * Botón central "+" elevado sobre la barra inferior (solo móvil).
 */
export default function FloatingActionButton({
	onClick,
	"aria-label": ariaLabel = "Más opciones",
	className,
	disabled = false,
}) {
	return (
		<button
			type="button"
			onClick={onClick}
			disabled={disabled}
			aria-label={ariaLabel}
			className={clsx(
				"flex size-14 shrink-0 items-center justify-center rounded-full border-2 border-white bg-famedic-lime text-famedic-darker shadow-lg shadow-famedic-dark/20 transition",
				"hover:scale-[1.03] hover:shadow-xl active:scale-95",
				"focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-dark focus-visible:ring-offset-2 dark:border-slate-900 dark:text-famedic-darker",
				"disabled:pointer-events-none disabled:opacity-50",
				className,
			)}
		>
			<PlusIcon className="size-8" />
		</button>
	);
}
