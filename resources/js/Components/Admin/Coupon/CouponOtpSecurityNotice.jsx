import clsx from "clsx";
import { LockClosedIcon } from "@heroicons/react/24/outline";

const COPY = {
	creation:
		"Esta operación está protegida con verificación OTP. Al confirmar, recibirás un código por correo o SMS.",
	approval:
		"La aprobación está protegida con verificación OTP. Recibirás un código por correo o SMS antes de confirmar.",
};

export default function CouponOtpSecurityNotice({
	required = true,
	variant = "creation",
	compact = false,
	className = "",
}) {
	if (!required) {
		return null;
	}

	const message = COPY[variant] ?? COPY.creation;

	if (compact) {
		return (
			<p
				className={clsx(
					"flex items-start gap-1.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400",
					className,
				)}
			>
				<LockClosedIcon
					className="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
					aria-hidden
				/>
				<span>{message}</span>
			</p>
		);
	}

	return (
		<div
			className={clsx(
				"flex items-start gap-2 rounded-lg border border-emerald-200/80 bg-emerald-50/70 px-3 py-2.5 dark:border-emerald-900/50 dark:bg-emerald-950/30",
				className,
			)}
		>
			<LockClosedIcon
				className="mt-0.5 size-4 shrink-0 text-emerald-700 dark:text-emerald-300"
				aria-hidden
			/>
			<p className="text-sm leading-relaxed text-emerald-950 dark:text-emerald-100">{message}</p>
		</div>
	);
}
