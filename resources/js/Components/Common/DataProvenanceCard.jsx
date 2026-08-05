import DataSourceBadge from "./DataSourceBadge";
import DataQualityBadge from "./DataQualityBadge";
import {
	resolveMode,
	resolveQuality,
	resolveSource,
} from "./dataProvenanceConstants";

/**
 * Tarjeta de procedencia completa — Drawer, Dashboard e integraciones futuras.
 */
export default function DataProvenanceCard({
	source = "HYBRID",
	mode = "LOCAL",
	endpoint = null,
	service = null,
	updatedAt = null,
	ttl = null,
	quality = "C",
	owner = null,
	apiVersion = null,
	title = "Procedencia del dato",
	className = "",
}) {
	const sourceMeta = resolveSource(source);
	const modeMeta = resolveMode(mode);
	const qualityMeta = resolveQuality(quality);

	const rows = [
		{ label: "Fuente", value: sourceMeta.legend },
		{ label: "Modo", value: modeMeta.label },
		...(endpoint ? [{ label: "Endpoint", value: endpoint }] : []),
		...(service ? [{ label: "Servicio", value: service }] : []),
		...(updatedAt ? [{ label: "Última lectura", value: updatedAt }] : []),
		...(ttl ? [{ label: "TTL", value: ttl }] : []),
		{
			label: "Calidad",
			value: (
				<span className="inline-flex items-center gap-1.5">
					<DataQualityBadge quality={quality} />
					<span>
						{qualityMeta.title} — {qualityMeta.description}
					</span>
				</span>
			),
		},
		...(owner ? [{ label: "Owner", value: owner }] : []),
		...(apiVersion ? [{ label: "Versión API", value: apiVersion }] : []),
	];

	return (
		<article
			className={`rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 ${className}`}
		>
			<div className="mb-3 flex flex-wrap items-center justify-between gap-2">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
					{title}
				</h3>
				<DataSourceBadge
					source={source}
					mode={mode}
					quality={quality}
					ttl={ttl}
					updatedAt={updatedAt}
					endpoint={endpoint}
				/>
			</div>
			<dl className="space-y-2">
				{rows.map((row) => (
					<div
						key={row.label}
						className="grid grid-cols-[7rem_1fr] gap-2 border-t border-zinc-100 pt-2 text-xs first:border-0 first:pt-0 dark:border-zinc-800"
					>
						<dt className="font-medium text-zinc-400">{row.label}</dt>
						<dd className="break-all font-medium text-zinc-800 dark:text-zinc-100">
							{row.value}
						</dd>
					</div>
				))}
			</dl>
		</article>
	);
}
