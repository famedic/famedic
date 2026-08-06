import { useRef, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	DocumentIcon,
	PhotoIcon,
} from "@heroicons/react/16/solid";

const IMAGE_TYPES = new Set([
	"image/jpeg",
	"image/png",
	"image/webp",
	"image/gif",
]);
const PDF_TYPE = "application/pdf";
const MAX_BYTES = 10 * 1024 * 1024;

/**
 * FASE 2 — Upload. Images supported by current API.
 * PDF selectable with clear guidance (API is image-only for now).
 */
export default function InterpretUpload({ onFileReady, busy = false }) {
	const imageInputRef = useRef(null);
	const pdfInputRef = useRef(null);
	const [dragOver, setDragOver] = useState(false);
	const [previewUrl, setPreviewUrl] = useState(null);
	const [fileName, setFileName] = useState(null);
	const [error, setError] = useState(null);
	const [pendingFile, setPendingFile] = useState(null);

	const revokePreview = () => {
		if (previewUrl?.startsWith("blob:")) {
			URL.revokeObjectURL(previewUrl);
		}
	};

	const acceptFile = (file) => {
		if (!file) return;
		setError(null);

		if (file.type === PDF_TYPE) {
			setPendingFile(null);
			revokePreview();
			setPreviewUrl(null);
			setFileName(file.name);
			setError(
				"Por ahora interpreta imágenes (JPG, PNG, WEBP o GIF). Toma una foto o captura de la orden. El PDF se habilitará sin cambiar el flujo.",
			);
			return;
		}

		if (!IMAGE_TYPES.has(file.type)) {
			setPendingFile(null);
			setError("Formato no válido. Usa JPG, PNG, WEBP, GIF o PDF.");
			return;
		}

		if (file.size > MAX_BYTES) {
			setPendingFile(null);
			setError("El archivo supera 10 MB.");
			return;
		}

		revokePreview();
		const url = URL.createObjectURL(file);
		setPreviewUrl(url);
		setFileName(file.name);
		setPendingFile(file);
	};

	const start = () => {
		if (!pendingFile || busy) return;
		onFileReady?.(pendingFile, previewUrl);
	};

	return (
		<div className="mx-auto max-w-lg space-y-6">
			<div
				role="button"
				tabIndex={0}
				aria-label="Zona para soltar o seleccionar receta"
				onKeyDown={(e) => {
					if (e.key === "Enter" || e.key === " ") {
						e.preventDefault();
						if (!busy) imageInputRef.current?.click();
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
					if (busy) return;
					acceptFile(e.dataTransfer.files?.[0]);
				}}
				className={`flex min-h-[220px] flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition ${
					dragOver
						? "border-famedic-light bg-famedic-light/5 ring-2 ring-famedic-light/20"
						: "border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-950/40"
				}`}
			>
				{previewUrl ? (
					<img
						src={previewUrl}
						alt="Vista previa de la receta"
						className="mb-4 max-h-48 rounded-lg object-contain shadow-sm"
					/>
				) : (
					<div className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-white text-zinc-400 shadow-sm dark:bg-zinc-900">
						<PhotoIcon className="size-7" aria-hidden />
					</div>
				)}
				<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					{fileName ? fileName : "Arrastra una imagen o PDF de la orden"}
				</p>
				<p className="mt-1 text-xs text-zinc-400">
					JPG, PNG, WEBP, GIF · máx. 10 MB
				</p>
			</div>

			<input
				ref={imageInputRef}
				type="file"
				accept="image/jpeg,image/png,image/webp,image/gif"
				className="sr-only"
				aria-label="Seleccionar imagen"
				onChange={(e) => {
					acceptFile(e.target.files?.[0]);
					e.target.value = "";
				}}
			/>
			<input
				ref={pdfInputRef}
				type="file"
				accept="application/pdf"
				className="sr-only"
				aria-label="Seleccionar PDF"
				onChange={(e) => {
					acceptFile(e.target.files?.[0]);
					e.target.value = "";
				}}
			/>

			<div className="flex flex-wrap justify-center gap-2">
				<Button
					outline
					disabled={busy}
					onClick={() => imageInputRef.current?.click()}
				>
					<PhotoIcon data-slot="icon" />
					Seleccionar imagen
				</Button>
				<Button
					outline
					disabled={busy}
					onClick={() => pdfInputRef.current?.click()}
				>
					<DocumentIcon data-slot="icon" />
					Seleccionar PDF
				</Button>
			</div>

			{error && (
				<p className="text-center text-sm text-amber-700 dark:text-amber-300">
					{error}
				</p>
			)}

			<ul className="space-y-1.5 text-center text-[11px] text-zinc-400">
				<li>Buena luz y hoja completa</li>
				<li>Texto legible, sin recortes fuertes</li>
				<li>Una página por captura funciona mejor</li>
			</ul>

			<div className="flex justify-center pt-2">
				<Button disabled={!pendingFile || busy} onClick={start}>
					Interpretar receta
				</Button>
			</div>
		</div>
	);
}
