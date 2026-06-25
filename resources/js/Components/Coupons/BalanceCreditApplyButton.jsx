import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function BalanceCreditApplyButton({
	applied = false,
	canApply = true,
	onApply,
	onClear,
	applyLabel = "Aplicar saldo a favor",
	clearLabel = "Quitar saldo",
	className = "",
}) {
	if (applied) {
		return (
			<div
				className={[
					"rounded-lg border border-violet-200/80 bg-violet-50/50 px-3 py-2.5 dark:border-violet-800/40 dark:bg-violet-950/20",
					className,
				].join(" ")}
			>
				<Text className="text-sm font-semibold text-violet-900 dark:text-violet-100">
					Saldo aplicado
				</Text>
				<Button
					type="button"
					plain
					className="mt-1 w-full text-sm !text-violet-700 dark:!text-violet-300"
					onClick={onClear}
				>
					{clearLabel}
				</Button>
			</div>
		);
	}

	if (!canApply) {
		return null;
	}

	return (
		<Button
			type="button"
			color="famedic"
			className={["w-full text-sm", className].join(" ")}
			onClick={onApply}
		>
			{applyLabel}
		</Button>
	);
}
