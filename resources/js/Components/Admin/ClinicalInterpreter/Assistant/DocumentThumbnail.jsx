/**
 * Persistent recipe thumbnail so the operator always sees which document is in play.
 */
export default function DocumentThumbnail({
	previewUrl,
	fileName,
	size = "md",
	className = "",
}) {
	if (!previewUrl) return null;

	const sizeClass =
		size === "sm"
			? "h-14 w-11"
			: size === "lg"
				? "h-28 w-20"
				: "h-20 w-14";

	return (
		<div
			className={`inline-flex items-center gap-3 ${className}`}
			title={fileName || "Receta cargada"}
		>
			<div
				className={`${sizeClass} shrink-0 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 shadow-sm ring-1 ring-black/5 dark:border-zinc-700 dark:bg-zinc-800 dark:ring-white/5`}
			>
				<img
					src={previewUrl}
					alt={fileName ? `Receta: ${fileName}` : "Receta en interpretación"}
					className="h-full w-full object-cover"
				/>
			</div>
			{(fileName || size !== "sm") && (
				<div className="min-w-0 text-left">
					<p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
						Documento
					</p>
					{fileName && (
						<p className="truncate text-xs text-zinc-600 dark:text-zinc-300">
							{fileName}
						</p>
					)}
				</div>
			)}
		</div>
	);
}
