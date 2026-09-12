import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

const STATUS_META = {
	complete: { label: "Completo", color: "green" },
	recommended: { label: "Recomendado", color: "sky" },
	pending: { label: "Pendiente", color: "amber" },
	na: { label: "No aplica", color: "zinc" },
};

export default function MarketingCampaignChecklist({ items = [] }) {
	if (!items.length) return null;

	return (
		<div className="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
			<Text className="font-semibold">Checklist de completitud</Text>
			<ul className="mt-4 space-y-3">
				{items.map((item) => {
					const meta = STATUS_META[item.status] || STATUS_META.pending;
					return (
						<li
							key={item.key}
							className="flex items-start justify-between gap-3"
						>
							<div>
								<Text className="font-medium">{item.label}</Text>
								{item.detail && (
									<Text className="mt-0.5 text-sm text-zinc-500">
										{item.detail}
									</Text>
								)}
							</div>
							<Badge color={meta.color}>{meta.label}</Badge>
						</li>
					);
				})}
			</ul>
		</div>
	);
}
