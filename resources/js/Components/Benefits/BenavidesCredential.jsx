import { useEffect, useRef, useState } from "react";
import JsBarcode from "jsbarcode";
import {
	ArrowUturnLeftIcon,
	CheckIcon,
	ClipboardDocumentIcon,
} from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";
import {
	BENAVIDES_BARCODE_FORMAT,
	BENAVIDES_BARCODE_OPTIONS,
} from "@/lib/benavidesBarcode";

const BENAVIDES_CARD_TEMPLATE = "/images/benefits/benavides/card-template-integrated.png";
const CREDENTIAL_BARCODE_OPTIONS = {
	...BENAVIDES_BARCODE_OPTIONS,
	height: 58,
	width: 1.8,
};

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
	const [showBack, setShowBack] = useState(false);

	useEffect(() => {
		if (showBack || !barcodeRef.current || !code) return;

		try {
			JsBarcode(barcodeRef.current, code, CREDENTIAL_BARCODE_OPTIONS);
			setBarcodeFailed(false);
		} catch (error) {
			setBarcodeFailed(true);
		}
	}, [code, showBack]);

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
			<div className="relative aspect-[736/1023] overflow-hidden bg-sky-700">
				<img
					src={BENAVIDES_CARD_TEMPLATE}
					alt="Tarjeta de beneficio Farmacias Benavides"
					className="absolute inset-0 h-full w-full object-cover"
				/>

				<div className="absolute bottom-[5.9%] left-[14.5%] right-[14.5%] flex h-[7.6%] items-center justify-center">
					{showBack ? (
						<div className="flex h-full w-full items-center justify-between gap-3 rounded-xl bg-white/95 px-4 shadow-sm">
							<div>
								<p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
									Código
								</p>
								<p className="mt-0.5 break-all font-mono text-sm font-semibold tracking-normal text-slate-950 sm:text-base">
									{code}
								</p>
							</div>
							<Button
								type="button"
								outline
								onClick={copyCode}
								aria-live="polite"
								className="shrink-0"
							>
								{copied ? (
									<CheckIcon data-slot="icon" />
								) : (
									<ClipboardDocumentIcon data-slot="icon" />
								)}
								{copied ? "Copiado" : "Copiar"}
							</Button>
						</div>
					) : (
						<div className="h-full w-full bg-transparent px-[2%]">
							<svg
								ref={barcodeRef}
								role="img"
								aria-label={`Código de barras ${code}`}
								className={barcodeFailed ? "hidden" : "h-full w-full"}
							/>
							{barcodeFailed && (
								<p className="rounded-md bg-white/95 py-3 text-center text-xs text-slate-600">
									Usa el reverso para ver el código.
								</p>
							)}
						</div>
					)}
				</div>
			</div>

			<div className="px-5 py-4">
				<div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
					<div className="flex flex-col gap-1 text-sm text-slate-600 dark:text-slate-300">
						<p className="font-medium text-slate-950 dark:text-white">{holderName}</p>
						<p>
							Formato de código de barras:{" "}
							<span className="font-medium">{BENAVIDES_BARCODE_FORMAT}</span>.
						</p>
						{formattedDate && <p>Activado el {formattedDate}.</p>}
					</div>
					<Button
						type="button"
						outline
						onClick={() => setShowBack((current) => !current)}
						className="w-full shrink-0 sm:w-auto"
					>
						<ArrowUturnLeftIcon data-slot="icon" />
						{showBack ? "Frente" : "Reverso"}
					</Button>
				</div>
			</div>
		</section>
	);
}
