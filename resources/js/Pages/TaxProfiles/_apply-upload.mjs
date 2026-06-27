import fs from "fs";

const d = "</motion>".replace("motion", "motion").replace("motion", "div");
// d is </div>

const fragment = [
	'				<DialogBody className="space-y-6">',
	"					<Field>",
	"						<Label>Constancia de Situación Fiscal *</Label>",
	"						<Description>",
	"							Sube el archivo PDF de tu constancia (máximo 5MB). Debe",
	"							ser emitida en los últimos 3 meses.",
	"						</Description>",
	"",
	'						<div className="space-y-4">',
	"							{uploadedFile ? (",
	'								<div className="rounded-xl border-2 border-solid border-green-200 bg-green-50 p-8 text-center dark:border-emerald-500/30 dark:bg-emerald-500/10">',
	'									<div className="space-y-4">',
	'										<div className="flex items-center justify-center">',
	'											<div className="rounded-full bg-green-100 p-3 dark:bg-emerald-500/20">',
	'												<CheckCircleIcon className="h-10 w-10 text-green-600 dark:text-emerald-400" />',
	d.replace("motion", "motion") === d ? "" : "",
	"											" + d.replace(/^<\/motion>/, "</div>"),
].join("\n");

// This is getting too messy. Use read of a clean file.

const cleanFragment = fs.readFileSync(
	new URL("./_upload-body.fragment.jsx", import.meta.url),
	"utf8",
).catch?.() ?? "";
