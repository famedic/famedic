import clsx from "clsx";
import DataQualityBadge from "./DataQualityBadge";
import DataTooltip, { DataTooltipRows } from "./DataTooltip";
import {
	resolveMode,
	resolveQuality,
	resolveSource,
} from "./dataProvenanceConstants";

/**
 * Badge de procedencia reutilizable (Datadog / Grafana style).
 *
 * @param {object} props
 * @param {string} props.source
 * @param {string} [props.mode]
 * @param {string} [props.quality]
 * @param {string} [props.ttl]
 * @param {string} [props.updatedAt]
 * @param {string} [props.endpoint]
 * @param {string|import('react').ReactNode} [props.tooltip]
 * @param {boolean} [props.compact]
 */
export default function DataSourceBadge({
	source = "HYBRID",
	mode = "LOCAL",
	quality = "C",
	ttl = null,
	updatedAt = null,
	endpoint = null,
	tooltip = null,
	compact = false,
	className = "",
	showMode = true,
	showQuality = true,
}) {
	const sourceMeta = resolveSource(source);
	const modeMeta = resolveMode(mode);
	const qualityMeta = resolveQuality(quality);

	const tooltipContent =
		tooltip ??
		(
			<DataTooltipRows
				rows={[
					{ label: "Fuente", value: sourceMeta.legend },
					...(endpoint ? [{ label: "Endpoint", value: endpoint }] : []),
					{ label: "Modo", value: modeMeta.label },
					...(updatedAt ? [{ label: "Actualizado", value: updatedAt }] : []),
					...(ttl ? [{ label: "TTL", value: ttl }] : []),
					{
						label: "Calidad",
						value: `${qualityMeta.label} · ${qualityMeta.title}`,
					},
				]}
			/>
		);

	return (
		<DataTooltip content={tooltipContent}>
			<span
				tabIndex={0}
				className={clsx(
					"inline-flex max-w-full cursor-help items-center gap-1.5 rounded-lg border px-2 py-1 outline-none ring-offset-1 focus-visible:ring-2",
					sourceMeta.bg,
					sourceMeta.border,
					sourceMeta.ring,
					className,
				)}
			>
				<span className={clsx("size-1.5 shrink-0 rounded-full", sourceMeta.dot)} />
				<span className="min-w-0">
					<span
						className={clsx(
							"block truncate text-[10px] font-semibold leading-tight",
							sourceMeta.text,
						)}
					>
						{sourceMeta.label}
					</span>
					{!compact ? (
						<span className="block truncate text-[9px] leading-tight text-zinc-500 dark:text-zinc-400">
							{ttl && sourceMeta.key === "ACTIVECAMPAIGN_MIRROR"
								? `TTL ${ttl}`
								: sourceMeta.subtitle}
						</span>
					) : null}
				</span>
				{showMode ? (
					<span
						className={clsx(
							"shrink-0 rounded border px-1 py-0.5 text-[9px] font-bold uppercase tracking-wide",
							modeMeta.className,
						)}
					>
						{modeMeta.label}
					</span>
				) : null}
				{showQuality ? <DataQualityBadge quality={quality} /> : null}
			</span>
		</DataTooltip>
	);
}
