import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Code, Text, Strong } from "@/Components/Catalyst/text";
import { DocumentTextIcon, PlusIcon } from "@heroicons/react/24/outline";

function personTypeLabel(tipoPersona) {
	if (tipoPersona === "fisica") return "Persona Física";
	if (tipoPersona === "moral") return "Persona Moral";
	return "No especificado";
}

export default function TaxProfileViewModal({ isOpen, close, taxProfile }) {
	if (!taxProfile) {
		return null;
	}

	const isUsed = taxProfile.is_used === true;
	const isDefault = taxProfile.is_default === true;

	return (
		<Dialog open={isOpen} onClose={close} size="lg">
			<DialogTitle>Datos del perfil fiscal</DialogTitle>
			{isUsed && (
				<DialogDescription className="text-left">
					Este perfil fiscal ya fue utilizado en una solicitud de factura
					y no puede editarse.
				</DialogDescription>
			)}
			<DialogBody>
				<div className="space-y-4">
					<div className="flex flex-wrap gap-2">
						{isDefault && (
							<Badge color="emerald">Predeterminado</Badge>
						)}
						{isUsed && (
							<Badge color="zinc">Utilizado en facturación</Badge>
						)}
					</div>

					<div>
						<p className="text-sm text-zinc-500 dark:text-slate-400">
							Nombre o razón social
						</p>
						<p className="font-medium text-zinc-900 dark:text-white">
							{taxProfile.name}
						</p>
					</div>

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
						<div>
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								RFC
							</p>
							<Code className="text-sm">{taxProfile.rfc}</Code>
						</div>
						<div>
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								Código postal
							</p>
							<p className="font-medium text-zinc-900 dark:text-white">
								CP {taxProfile.zipcode}
							</p>
						</div>
						<div>
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								Tipo de persona
							</p>
							<p className="font-medium text-zinc-900 dark:text-white">
								{personTypeLabel(taxProfile.tipo_persona)}
							</p>
						</div>
						<div>
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								Predeterminado
							</p>
							<p className="font-medium text-zinc-900 dark:text-white">
								{isDefault ? "Sí" : "No"}
							</p>
						</div>
					</div>

					<div>
						<p className="mb-1 text-sm text-zinc-500 dark:text-slate-400">
							Régimen fiscal
						</p>
						<Badge color="slate" className="w-full justify-center py-1.5 sm:w-auto">
							{taxProfile.formatted_tax_regime}
						</Badge>
					</div>

					{taxProfile.formatted_cfdi_use && (
						<div>
							<p className="mb-1 text-sm text-zinc-500 dark:text-slate-400">
								Uso CFDI del perfil
							</p>
							<Badge color="slate" className="w-full justify-center py-1.5 sm:w-auto">
								{taxProfile.formatted_cfdi_use}
							</Badge>
						</div>
					)}

					{isUsed && (
						<Text className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
							<Strong>Importante:</Strong> si necesitas datos fiscales
							distintos, crea otro perfil. Tus solicitudes y facturas
							anteriores no se alteran.
						</Text>
					)}
				</div>
			</DialogBody>
			<DialogActions className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
				<Button plain type="button" onClick={close} autoFocus>
					Cerrar
				</Button>
				<a
					href={route("tax-profiles.fiscal-certificate", {
						tax_profile: taxProfile,
					})}
					target="_blank"
					rel="noreferrer"
				>
					<Button type="button" outline>
						<DocumentTextIcon data-slot="icon" className="h-4 w-4" />
						Ver constancia
					</Button>
				</a>
				<Button
					href={route("tax-profiles.create")}
					preserveState
					preserveScroll
					type="button"
				>
					<PlusIcon data-slot="icon" className="h-4 w-4" />
					Crear otro perfil
				</Button>
			</DialogActions>
		</Dialog>
	);
}
