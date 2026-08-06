import { Badge } from "@/Components/Catalyst/badge";

const TRUTH_MAP = {
	disponible: { label: "Disponible", color: "emerald" },
	proxy: { label: "Proxy", color: "amber" },
	proximamente: { label: "Próximamente", color: "zinc" },
	instrumentacion: { label: "Requiere instrumentación", color: "violet" },
};

export default function ContactTruthBadge({ truth }) {
	const meta = TRUTH_MAP[truth] || TRUTH_MAP.proximamente;

	return (
		<Badge
			color={meta.color}
			className="!px-1.5 !py-0.5 !text-[10px] !font-semibold uppercase tracking-wide"
		>
			{meta.label}
		</Badge>
	);
}
