import { useEffect, useRef, useState } from "react";
import JsBarcode from "jsbarcode";
import {
	CheckIcon,
	ClipboardDocumentIcon,
} from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";
import {
	BENAVIDES_BARCODE_FORMAT,
	BENAVIDES_BARCODE_OPTIONS,
} from "@/lib/benavidesBarcode";

function fallbackCopy(text) {
	const textArea = document.createElement("textarea");
	textArea.value = text;
	textArea.setAttribute("readonly", "");
	textArea.style.position = "fixed";
	textArea.style.top = "-9999px";
	document.body.appendChild(textArea);
	textArea.select();

	try {
		return document.execCommand("copy");
	} finally {
		document.body.removeChild(textArea);
	}
}

export default function BenavidesCredential({ code, holderName, assignedAt }) {
	const barcodeRef = useRef(null);
	const [barcodeFailed, setBarcodeFailed] = useState(false);
	const [copied, setCopied] = useState(false);

	useEffect(() => {
		if (!barcodeRef.current || !code) return;

		try {
			JsBarcode(barcodeRef.current, code, BENAVIDES_BARCODE_OPTIONS);
			setBarcodeFailed(false);
		} catch (error) {
			setBarcodeFailed(true);
		}
	}, [code]);

	const copyCode = async () => {
		try {
			if (navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(code);
			} else if (!fallbackCopy(code)) {
				throw new Error("Clipboard fallback failed");
			}

			setCopied(true);
			window.setTimeout(() => setCopied(false), 2200);
		} catch (error) {
			setCopied(false);
		}
	};

	const formattedDate = assignedAt
		? new Intl.DateTimeFormat("es-MX", {
				day: "2-digit",
				month: "long",
				year: "numeric",
			}).format(new Date(assignedAt))
		: null;

	return (
		<section
			aria-label="Credencial Farmacias Benavides"
			className="w-full max-w-md overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"
		>
			<div className="bg-famedic-dark px-5 py-5 text-white dark:bg-slate-950">
				<p className="text-xs font-semibold uppercase tracking-[0.08em] text-famedic-lime">
					FAMEDIC x Farmacias Benavides
				</p>
				<div className="mt-4 flex items-start justify-between gap-4">
					<div className="min-w-0">
						<h2 className="text-xl font-semibold leading-tight">Credencial de beneficio</h2>
						<p className="mt-1 truncate text-sm text-white/75">{holderName}</p>
					</div>
					<div className="rounded-md border border-white/15 px-2.5 py-1 text-xs font-semibold uppercase text-white/80">
						Activa
					</div>
				</div>
			</div>

			<div className="space-y-5 px-5 py-5">
				<div>
					<p className="text-xs font-medium uppercase tracking-[0.08em] text-slate-500 dark:text-slate-400">
						Código
					</p>
					<div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
						<p className="break-all font-mono text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">
							{code}
						</p>
						<Button
							type="button"
							outline
							onClick={copyCode}
							aria-live="polite"
							className="w-full sm:w-auto"
						>
							{copied ? (
								<CheckIcon data-slot="icon" />
							) : (
								<ClipboardDocumentIcon data-slot="icon" />
							)}
							{copied ? "Código copiado" : "Copiar código"}
						</Button>
					</div>
				</div>

				<div className="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700">
					<svg
						ref={barcodeRef}
						role="img"
						aria-label={`Código de barras ${code}`}
						className={barcodeFailed ? "hidden" : "h-24 w-full"}
					/>
					{barcodeFailed && (
						<p className="py-8 text-center text-sm text-slate-600">
							Muestra este código en texto: <span className="font-mono">{code}</span>
						</p>
					)}
				</div>

				<div className="flex flex-col gap-1 border-t border-slate-200 pt-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
					<p>
						Formato de código de barras:{" "}
						<span className="font-medium">{BENAVIDES_BARCODE_FORMAT}</span>.
					</p>
					{formattedDate && <p>Activado el {formattedDate}.</p>}
				</div>
			</div>
		</section>
	);
}
