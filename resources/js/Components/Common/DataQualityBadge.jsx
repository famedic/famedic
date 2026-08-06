import clsx from "clsx";
import { resolveQuality } from "./dataProvenanceConstants";

export default function DataQualityBadge({ quality = "C", className = "" }) {
	const meta = resolveQuality(quality);

	return (
		<span
			className={clsx(
				"inline-flex size-5 items-center justify-center rounded border text-[10px] font-bold leading-none shadow-sm",
				meta.className,
				className,
			)}
			title={`${meta.label} · ${meta.title} — ${meta.description}`}
			aria-label={`Calidad ${meta.label}: ${meta.title}`}
		>
			{meta.label}
		</span>
	);
}
