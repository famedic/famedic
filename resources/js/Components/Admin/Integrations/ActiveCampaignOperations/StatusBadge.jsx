import { Badge } from "@/Components/Catalyst/badge";

const STATUS = {
	healthy: { label: "Operativa", color: "emerald" },
	error: { label: "Error", color: "rose" },
	disabled: { label: "Off", color: "zinc" },
	unknown: { label: "Sin probar", color: "amber" },
	synced: { label: "OK", color: "emerald" },
	failed: { label: "Error", color: "rose" },
	pending: { label: "Pendiente", color: "amber" },
	processing: { label: "Procesando", color: "sky" },
	skipped: { label: "Omitido", color: "zinc" },
};

export default function StatusBadge({ status, label = null }) {
	const meta = STATUS[status] || { label: label || status || "—", color: "zinc" };

	return (
		<Badge
			color={meta.color}
			className="!px-1.5 !py-0.5 !text-[10px] !font-semibold uppercase tracking-wide"
		>
			{label || meta.label}
		</Badge>
	);
}
