import MetricCard from "./MetricCard";
import SectionHeader from "./SectionHeader";
import StatusBadge from "./StatusBadge";
import { provenanceForSection } from "./provenanceCatalog";

const CARD_PROVENANCE = {
	status: { source: "ACTIVECAMPAIGN_API", mode: "LIVE", quality: "A", endpoint: "health probe" },
	latency: { source: "ACTIVECAMPAIGN_API", mode: "LIVE", quality: "A", endpoint: "response_ms" },
	env: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "A", endpoint: "config/services.activecampaign" },
	rate: { source: "ACTIVECAMPAIGN_API", mode: "LIVE", quality: "B", endpoint: "rate limit headers" },
	last_req: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "B", endpoint: "ops meta / last request" },
	last_err: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "B", endpoint: "ops meta / last error" },
	version: { source: "ACTIVECAMPAIGN_API", mode: "LIVE", quality: "A", endpoint: "API v3" },
	host: { source: "FAMEDIC_DATABASE", mode: "LOCAL", quality: "A", endpoint: "config endpoint host" },
};

export default function ApiHealthCard({ health, updatedAt = null }) {
	const tone =
		health?.status === "healthy"
			? "emerald"
			: health?.status === "error"
				? "rose"
				: health?.status === "disabled"
					? "default"
					: "amber";

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="API Health"
				description="Estado del cliente HTTP ActiveCampaign (API v3)."
				provenance={provenanceForSection("api_health")}
				updatedAt={updatedAt || health?.last_request_at}
				action={<StatusBadge status={health?.status} label={health?.status_label} />}
			/>
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<MetricCard
					label="Estado API"
					value={health?.status_label}
					tone={tone}
					provenance={CARD_PROVENANCE.status}
					updatedAt={updatedAt}
				/>
				<MetricCard
					label="Tiempo de respuesta"
					value={
						typeof health?.response_ms === "number"
							? `${health.response_ms} ms`
							: health?.response_ms
					}
					provenance={CARD_PROVENANCE.latency}
					updatedAt={updatedAt}
				/>
				<MetricCard
					label="Environment"
					value={health?.environment}
					provenance={CARD_PROVENANCE.env}
				/>
				<MetricCard
					label="Rate limit"
					value={health?.rate_limit}
					provenance={CARD_PROVENANCE.rate}
				/>
				<MetricCard
					label="Última petición"
					value={health?.last_request_at}
					provenance={CARD_PROVENANCE.last_req}
				/>
				<MetricCard
					label="Último error"
					value={health?.last_error}
					tone={
						health?.last_error && health.last_error !== "No disponible"
							? "rose"
							: "default"
					}
					provenance={CARD_PROVENANCE.last_err}
				/>
				<MetricCard
					label="Versión API"
					value={health?.api_version}
					provenance={CARD_PROVENANCE.version}
				/>
				<MetricCard
					label="Endpoint"
					value={health?.endpoint_host}
					provenance={CARD_PROVENANCE.host}
				/>
			</div>
		</section>
	);
}
