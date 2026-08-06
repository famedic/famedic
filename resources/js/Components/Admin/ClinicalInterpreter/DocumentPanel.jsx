import { useRef, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	MagnifyingGlassPlusIcon,
	ArrowPathIcon,
	DocumentIcon,
	PhotoIcon,
} from "@heroicons/react/16/solid";

const ACCEPTED_TYPES = new Set([
	"image/jpeg",
	"image/png",
	"image/webp",
	"image/gif",
]);
const MAX_BYTES = 10 * 1024 * 1024;

export default function DocumentPanel({
	document,
	previewUrl,
	interpreting = false,
	onFileSelected,
	onRetry,
	error = null,
}) {
	const inputRef = useRef(null);
	const [dragOver, setDragOver] = useState(false);
	const [localError, setLocalError] = useState(null);
	const pages = document?.pages || 1;
	const src = previewUrl || document?.preview_url || null;

	const validateAndSend = (file) => {
		if (!file) return;
		setLocalError(null);

		if (!ACCEPTED_TYPES.has(file.type)) {
			setLocalError("Formato no válido. Usa JPG, PNG, WEBP o GIF.");
			return;
		}
		if (file.size > MAX_BYTES) {
			setLocalError("El archivo supera 10 MB.");
			return;
		}

		onFileSelected?.(file);
	};

	return (
		<section className="flex h-full min-h-[320px] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<header className="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Panel 1
					</p>
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Documento original
					</h2>
				</div>
				<Badge color="zinc">{document?.filename || "receta"}</Badge>
			</header>

			<div className="flex flex-wrap gap-2 border-b border-zinc-100 px-4 py-2 dark:border-zinc-800">
				<Button
					outline
					disabled
					className="!py-1.5 text-xs"
					title="Próximamente"
				>
					<MagnifyingGlassPlusIcon data-slot="icon" />
					Zoom
				</Button>
				<Button
					outline
					disabled
					className="!py-1.5 text-xs"
					title="Próximamente"
				>
					<ArrowPathIcon data-slot="icon" />
					Rotar
				</Button>
				<Button
					outline
					disabled
					className="!py-1.5 text-xs"
					title="Próximamente"
				>
					<DocumentIcon data-slot="icon" />
					Páginas ({pages})
				</Button>
			</div>

			<div className="flex flex-1 flex-col items-center justify-center gap-3 bg-zinc-50 p-4 dark:bg-zinc-950/60">
				<div
					role="button"
					tabIndex={0}
					aria-label="Zona para soltar o seleccionar imagen de receta"
					onKeyDown={(e) => {
						if (e.key === "Enter" || e.key === " ") {
							e.preventDefault();
							if (!interpreting) inputRef.current?.click();
						}
					}}
					onDragOver={(e) => {
						e.preventDefault();
						setDragOver(true);
					}}
					onDragLeave={() => setDragOver(false)}
					onDrop={(e) => {
						e.preventDefault();
						setDragOver(false);
						if (interpreting) return;
						validateAndSend(e.dataTransfer.files?.[0]);
					}}
					className={`flex aspect-[3/4] w-full max-w-[220px] flex-col items-center justify-center overflow-hidden rounded-lg border border-dashed bg-white text-center shadow-inner transition dark:bg-zinc-900 ${
						dragOver
							? "border-famedic-light ring-2 ring-famedic-light/30"
							: "border-zinc-300 dark:border-zinc-600"
					}`}
				>
					{src ? (
						<img
							src={src}
							alt="Vista previa de la receta médica"
							className="h-full w-full object-contain"
						/>
					) : (
						<div className="p-4">
							<div className="mb-3 h-24 w-full rounded bg-zinc-100 dark:bg-zinc-800" />
							<p className="text-xs font-medium text-zinc-700 dark:text-zinc-200">
								Arrastra una imagen aquí
							</p>
							<p className="mt-1 text-[11px] text-zinc-400">
								JPG, PNG, WEBP o GIF · máx. 10 MB
							</p>
						</div>
					)}
				</div>

				<input
					ref={inputRef}
					id="clinical-interpreter-document"
					type="file"
					accept="image/jpeg,image/png,image/webp,image/gif"
					className="sr-only"
					aria-label="Seleccionar imagen de receta"
					onChange={(e) => {
						validateAndSend(e.target.files?.[0]);
						e.target.value = "";
					}}
				/>

				<div className="flex flex-wrap justify-center gap-2">
					<Button
						outline
						className="!py-1.5 text-xs"
						disabled={interpreting}
						onClick={() => inputRef.current?.click()}
					>
						<PhotoIcon data-slot="icon" />
						{interpreting ? "Interpretando…" : "Subir e interpretar"}
					</Button>
					{error && onRetry && (
						<Button
							outline
							className="!py-1.5 text-xs"
							disabled={interpreting}
							onClick={onRetry}
						>
							Reintentar
						</Button>
					)}
				</div>

				{(localError || error) && (
					<p className="max-w-[240px] text-center text-xs text-red-600 dark:text-red-400">
						{localError || error}
					</p>
				)}

				{interpreting && (
					<p className="text-[11px] text-zinc-400">
						Procesando con OpenAI Vision…
					</p>
				)}
			</div>
		</section>
	);
}
