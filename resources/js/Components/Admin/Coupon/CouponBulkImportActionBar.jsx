import { Button } from "@/Components/Catalyst/button";

const ACTIONS = [
	{ id: "selectAll", label: "Seleccionar todos" },
	{ id: "selectRegistered", label: "Solo registrados" },
	{ id: "deselectAll", label: "Deseleccionar todos" },
	{ id: "deselectInvalid", label: "Deseleccionar inválidos" },
	{ id: "removeDuplicates", label: "Eliminar duplicados" },
	{ id: "removeInvalid", label: "Quitar inválidos" },
	{ id: "resetSelection", label: "Reiniciar selección" },
];

export default function CouponBulkImportActionBar({ onAction }) {
	return (
		<div className="flex flex-wrap gap-2">
			{ACTIONS.map((action) => (
				<Button
					key={action.id}
					type="button"
					outline
					className="text-sm"
					onClick={() => onAction(action.id)}
				>
					{action.label}
				</Button>
			))}
		</div>
	);
}
