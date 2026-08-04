import { Badge } from "@/Components/Catalyst/badge";

export default function TaxProfileStatusBadge({
	isActive = true,
	isDefault = false,
	usageStatus = null,
}) {
	return (
		<div className="flex flex-wrap gap-1">
			<Badge color={isActive ? "lime" : "zinc"}>
				{isActive ? "Activo" : "Eliminado"}
			</Badge>
			{isDefault ? <Badge color="sky">Predeterminado</Badge> : null}
			{usageStatus === "unused" ? (
				<Badge color="amber">Sin uso</Badge>
			) : null}
			{usageStatus === "used" ? <Badge color="zinc">En uso</Badge> : null}
		</div>
	);
}
