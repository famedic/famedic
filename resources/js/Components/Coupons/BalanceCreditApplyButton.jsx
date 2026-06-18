import { Button } from "@/Components/Catalyst/button";

export default function BalanceCreditApplyButton({
	applied = false,
	canApply = true,
	onApply,
	onClear,
	applyLabel = "Aplicar saldo a favor",
	clearLabel = "Quitar saldo aplicado",
	className = "",
}) {
	if (applied) {
		return (
			<Button
				type="button"
				plain
				className={["w-full text-sm", className].join(" ")}
				onClick={onClear}
			>
				{clearLabel}
			</Button>
		);
	}

	return (
		<Button
			type="button"
			color="emerald"
			className={["w-full text-sm", className].join(" ")}
			disabled={!canApply}
			onClick={onApply}
		>
			{applyLabel}
		</Button>
	);
}
