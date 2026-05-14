import clsx from "clsx";
import { CreditCardIcon } from "@heroicons/react/24/outline";
import OdessaLogo from "@/Components/OdessaLogo";
import EfevooPayLogo from "@/Components/EfevooPayLogo";

const STRIPE_SI = "https://cdn.simpleicons.org/stripe/635BFF";
const PAYPAL_SI_LIGHT = "https://cdn.simpleicons.org/paypal/003087";
const PAYPAL_SI_DARK = "https://cdn.simpleicons.org/paypal/FFFFFF";

/**
 * Logos / iconos del método de pago (tamaño base: ~24–28px de alto en logos cuadrados).
 *
 * @param {string|null|undefined} method
 * @param {string|null|undefined} label
 * @param {"sm"|"md"} size
 */
export default function PaymentMethodDisplayIcon({ method, label, size = "md", className = "" }) {
	const readable = (label && String(label).trim()) || method || "Método de pago";
	const box =
		size === "sm"
			? "min-h-9 min-w-9 rounded-lg p-1"
			: "min-h-10 min-w-10 rounded-lg p-1";

	const imgH = size === "sm" ? "h-6 w-6" : "h-7 w-7";

	if (!method) {
		return (
			<span
				className={clsx(
					"inline-flex items-center justify-center text-zinc-400 dark:text-slate-500",
					box,
					className,
				)}
				title={readable}
			>
				<span className="sr-only">{readable}</span>
				<span aria-hidden className="text-lg font-medium">
					—
				</span>
			</span>
		);
	}

	const common = clsx("inline-flex items-center justify-center", box, className);

	switch (method) {
		case "stripe":
			return (
				<span className={common} title={readable}>
					<span className="sr-only">{readable}</span>
					<img src={STRIPE_SI} alt="" className={clsx(imgH, "object-contain dark:opacity-95")} aria-hidden />
				</span>
			);
		case "odessa":
			return (
				<span className={common} title={readable}>
					<span className="sr-only">{readable}</span>
					<OdessaLogo
						className={
							size === "sm" ? "h-6 w-auto max-w-[4.5rem] object-contain" : "h-8 w-auto max-w-[5.5rem] object-contain"
						}
					/>
				</span>
			);
		case "efevoopay":
			return (
				<span
					className={clsx(
						common,
						"border border-violet-200/90 bg-violet-50/90 dark:border-violet-800/80 dark:bg-violet-950/40",
					)}
					title={readable}
				>
					<span className="sr-only">{readable}</span>
					<EfevooPayLogo
						className={
							size === "sm" ? "size-6 text-violet-700 dark:text-violet-300" : "size-7 text-violet-700 dark:text-violet-300"
						}
						aria-hidden
					/>
				</span>
			);
		case "paypal":
			return (
				<span className={common} title={readable}>
					<span className="sr-only">{readable}</span>
					<img src={PAYPAL_SI_LIGHT} alt="" className={clsx(imgH, "object-contain dark:hidden")} aria-hidden />
					<img src={PAYPAL_SI_DARK} alt="" className={clsx(imgH, "hidden object-contain dark:block")} aria-hidden />
				</span>
			);
		default:
			return (
				<span
					className={clsx(
						common,
						"border border-zinc-200 bg-zinc-50 dark:border-slate-600 dark:bg-slate-800/80",
					)}
					title={readable}
				>
					<span className="sr-only">{readable}</span>
					<CreditCardIcon
						className={
							size === "sm" ? "size-5 text-zinc-600 dark:text-slate-300" : "size-6 text-zinc-600 dark:text-slate-300"
						}
						aria-hidden
					/>
				</span>
			);
	}
}
