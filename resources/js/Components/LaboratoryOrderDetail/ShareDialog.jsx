import { router } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import { Input } from "@/Components/Catalyst/input";
import { buildEmailShareUrl, buildLaboratoryOrderShareMessage, buildWhatsAppShareUrl } from "@/lib/laboratoryOrderShare";
import {
	ClipboardDocumentIcon,
	EnvelopeIcon,
	LinkIcon,
	ShareIcon,
	UserGroupIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";

export default function ShareDialog({ isOpen, onClose, purchaseId, activeShare = null }) {
	const [share, setShare] = useState(activeShare);
	const [shareUrl, setShareUrl] = useState("");
	const [processing, setProcessing] = useState(false);
	const [error, setError] = useState("");
	const [copied, setCopied] = useState(false);

	useEffect(() => {
		if (isOpen) setShare(activeShare);
	}, [activeShare, isOpen]);

	const whatsAppUrl = useMemo(() => (shareUrl ? buildWhatsAppShareUrl(shareUrl) : "#"), [shareUrl]);
	const emailUrl = useMemo(() => (shareUrl ? buildEmailShareUrl(shareUrl) : "#"), [shareUrl]);

	const createShare = async () => {
		if (!purchaseId || processing) return;

		setProcessing(true);
		setError("");
		setCopied(false);

		try {
			const { data } = await window.axios.post(
				route("laboratory-purchases.shares.store", { laboratory_purchase: purchaseId }),
			);
			setShare(data.share);
			setShareUrl(data.url);
		} catch (err) {
			setError(err?.response?.data?.message || "No pudimos generar el enlace. Intenta de nuevo.");
		} finally {
			setProcessing(false);
		}
	};

	const revokeShare = async () => {
		if (!purchaseId || !share?.id || processing) return;

		setProcessing(true);
		setError("");
		setCopied(false);

		try {
			const { data } = await window.axios.delete(
				route("laboratory-purchases.shares.destroy", {
					laboratory_purchase: purchaseId,
					share: share.id,
				}),
			);
			setShare(data.share);
			setShareUrl("");
			router.reload({ only: ["activeLaboratoryPurchaseShare"] });
		} catch (err) {
			setError(err?.response?.data?.message || "No pudimos desactivar el enlace. Intenta de nuevo.");
		} finally {
			setProcessing(false);
		}
	};

	const copyShareUrl = async () => {
		if (!shareUrl) return;

		try {
			if (!window.navigator.clipboard?.writeText) {
				throw new Error("clipboard_unavailable");
			}

			await window.navigator.clipboard.writeText(shareUrl);
			setCopied(true);
		} catch {
			setCopied(false);
			setError("No pudimos copiar automaticamente. Selecciona el enlace y copialo manualmente.");
		}
	};

	const nativeShare = async () => {
		if (!shareUrl || !window.navigator.share) return;

		await window.navigator.share({
			title: "Cita de laboratorio FAMEDIC",
			text: buildLaboratoryOrderShareMessage(shareUrl),
			url: shareUrl,
		});
	};

	const hasActiveShareWithoutUrl = share?.id && !share?.revoked_at && !shareUrl;
	const canUseNativeShare = Boolean(shareUrl && typeof window !== "undefined" && window.navigator.share);

	return (
		<Dialog open={isOpen} onClose={onClose} size="lg">
			<DialogTitle>Compartir orden de laboratorio</DialogTitle>
			<DialogDescription>
				Genera un enlace temporal para que un familiar o amigo pueda consultar tu orden de compra.
			</DialogDescription>

			<DialogBody className="space-y-5">
				<div className="flex gap-3 rounded-xl border border-sky-100 bg-sky-50/80 p-4 dark:border-sky-900/60 dark:bg-sky-950/30">
					<UserGroupIcon className="mt-0.5 size-5 shrink-0 text-sky-700 dark:text-sky-300" aria-hidden />
					<div className="min-w-0 space-y-1">
						<p className="text-sm font-semibold text-sky-950 dark:text-sky-100">
							Comparte fácilmente tu orden
						</p>
						<p className="text-sm text-sky-800 dark:text-sky-200">
							Ideal para que un familiar, amigo o cuidador pueda acompañarte o ayudarte con el seguimiento.
						</p>
					</div>
				</div>

				{hasActiveShareWithoutUrl && (
					<div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100">
						Ya existe un enlace activo hasta {share.formatted_expires_at || "su fecha de expiracion"}. Por
						seguridad, FAMEDIC no vuelve a mostrar enlaces ya generados. Crear uno nuevo desactivara el anterior.
					</div>
				)}

				{shareUrl && (
					<div className="space-y-3">
						<label className="block text-sm font-medium text-zinc-900 dark:text-white">
							Enlace para compartir
						</label>
						<Input readOnly value={shareUrl} onFocus={(event) => event.currentTarget.select()} />
						{share?.formatted_expires_at && (
							<p className="text-sm text-zinc-500 dark:text-slate-400">
								Valido hasta: {share.formatted_expires_at}
							</p>
						)}
					</div>
				)}

				{error && (
					<p className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100">
						{error}
					</p>
				)}

				{shareUrl && (
					<div className="grid gap-2 sm:grid-cols-2">
						{canUseNativeShare && (
							<Button type="button" outline onClick={nativeShare}>
								<ShareIcon data-slot="icon" className="size-4" />
								Compartir
							</Button>
						)}
						<Button type="button" outline onClick={copyShareUrl}>
							<ClipboardDocumentIcon data-slot="icon" className="size-4" />
							{copied ? "Copiado" : "Copiar"}
						</Button>
						<a href={whatsAppUrl} target="_blank" rel="noreferrer noopener">
							<Button type="button" outline className="w-full">
								<ShareIcon data-slot="icon" className="size-4" />
								WhatsApp
							</Button>
						</a>
						<a href={emailUrl}>
							<Button type="button" outline className="w-full">
								<EnvelopeIcon data-slot="icon" className="size-4" />
								Correo
							</Button>
						</a>
					</div>
				)}
			</DialogBody>

			<DialogActions>
				<Button plain type="button" onClick={onClose}>
					<XMarkIcon data-slot="icon" className="size-4" />
					Cerrar
				</Button>
				{share?.id && !share?.revoked_at && (
					<Button outline type="button" disabled={processing} onClick={revokeShare}>
						Desactivar enlace
					</Button>
				)}
				<Button
					type="button"
					className="w-full justify-center sm:w-auto"
					disabled={processing}
					onClick={createShare}
				>
					<LinkIcon data-slot="icon" className="size-4" />
					Generar enlace
				</Button>
			</DialogActions>
		</Dialog>
	);
}
