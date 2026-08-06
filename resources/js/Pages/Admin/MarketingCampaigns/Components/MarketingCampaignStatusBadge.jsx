import { Badge } from "@/Components/Catalyst/badge";

const CAMPAIGN_COLORS = {
	draft: "zinc",
	scheduled: "amber",
	active: "green",
	paused: "orange",
	finished: "slate",
	archived: "zinc",
};

const LINK_COLORS = {
	draft: "zinc",
	active: "lime",
	paused: "orange",
	archived: "zinc",
};

const CAMPAIGN_LABELS = {
	draft: "Borrador",
	scheduled: "Programada",
	active: "Activa",
	paused: "Pausada",
	finished: "Finalizada",
	archived: "Archivada",
};

const LINK_LABELS = {
	draft: "Borrador",
	active: "Activo",
	paused: "Pausado",
	archived: "Archivado",
};

function normalizeStatus(status) {
	if (status == null) return "";
	if (typeof status === "object") {
		return String(status.value ?? status.name ?? "");
	}
	return String(status);
}

export default function MarketingCampaignStatusBadge({
	status,
	label,
	kind = "campaign",
}) {
	const value = normalizeStatus(status).toLowerCase();
	const colors = kind === "link" ? LINK_COLORS : CAMPAIGN_COLORS;
	const labels = kind === "link" ? LINK_LABELS : CAMPAIGN_LABELS;

	return (
		<Badge color={colors[value] || "zinc"}>
			{label || labels[value] || value || "—"}
		</Badge>
	);
}
