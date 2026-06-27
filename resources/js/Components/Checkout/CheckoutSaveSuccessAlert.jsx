import { Text } from "@/Components/Catalyst/text";
import { CheckCircleIcon } from "@heroicons/react/24/solid";
import clsx from "clsx";

export default function CheckoutSaveSuccessAlert({
	message,
	hint = "Puedes continuar al siguiente paso.",
	className,
}) {
	return (
		<div
			className={clsx(
				"flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800/50 dark:bg-emerald-950/30",
				className,
			)}
			role="status"
			aria-live="polite"
		>
			<CheckCircleIcon className="mt-0.5 size-6 shrink-0 text-emerald-600 dark:text-emerald-400" />
			<div className="min-w-0">
				<Text className="font-medium text-emerald-900 dark:text-emerald-100">
					{message}
				</Text>
				{hint && (
					<Text className="mt-0.5 text-sm text-emerald-800/90 dark:text-emerald-200/90">
						{hint}
					</Text>
				)}
			</div>
		</div>
	);
}
