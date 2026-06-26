import { Text } from "@/Components/Catalyst/text";
import clsx from "clsx";

export default function MembershipProgress({
	progress,
	size = "lg",
	className,
}) {
	if (!progress) {
		return null;
	}

	const percentageRemaining = Math.max(
		0,
		Math.min(100, 100 - progress.percentageUsed),
	);
	const circumference = 2 * Math.PI * 42;
	const strokeDashoffset =
		circumference - (percentageRemaining / 100) * circumference;

	const dimensions = size === "sm" ? "size-24" : "size-32 sm:size-36";
	const valueClass =
		size === "sm"
			? "text-2xl font-bold"
			: "text-3xl font-bold sm:text-4xl";

	return (
		<div
			className={clsx("relative flex items-center justify-center", dimensions, className)}
			role="img"
			aria-label={`${progress.remainingDays} días restantes de membresía`}
		>
			<svg
				className="size-full -rotate-90"
				viewBox="0 0 100 100"
				aria-hidden="true"
			>
				<circle
					cx="50"
					cy="50"
					r="42"
					fill="none"
					stroke="currentColor"
					strokeWidth="8"
					className="text-white/15"
				/>
				<circle
					cx="50"
					cy="50"
					r="42"
					fill="none"
					stroke="currentColor"
					strokeWidth="8"
					strokeLinecap="round"
					strokeDasharray={circumference}
					strokeDashoffset={strokeDashoffset}
					className="text-white transition-all duration-700"
				/>
			</svg>
			<div className="absolute inset-0 flex flex-col items-center justify-center text-center">
				<span className={clsx("font-poppins leading-none text-white", valueClass)}>
					{progress.remainingDays}
				</span>
				<Text className="mt-0.5 text-[10px] text-white/70 sm:text-xs">
					días
				</Text>
			</div>
		</div>
	);
}
