import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import DataStatusBadge from "@/Components/Common/DataStatusBadge";
import { detectDataStatus } from "@/Components/Common/dataProvenanceConstants";

/**
 * Renderiza valor crudo o DataStatusBadge si detecta estados legacy.
 */
export function ProvenanceValue({ value, toneClassName = "" }) {
	const status = detectDataStatus(value);
	if (status) {
		return <DataStatusBadge status={status} detail={String(value)} />;
	}

	return (
		<span className={toneClassName}>{value == null || value === "" ? "—" : value}</span>
	);
}

export function SectionProvenance({ provenance, updatedAt = null }) {
	if (!provenance) {
		return null;
	}

	return (
		<DataSourceBadge
			source={provenance.source}
			mode={provenance.mode}
			quality={provenance.quality}
			ttl={provenance.ttl}
			endpoint={provenance.endpoint}
			updatedAt={updatedAt || provenance.updatedAt}
		/>
	);
}
