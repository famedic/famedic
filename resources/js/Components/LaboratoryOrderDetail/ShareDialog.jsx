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
	ArrowPathIcon,
	ClipboardDocumentIcon,
	EnvelopeIcon,
	ShareIcon,
	XMarkIcon,
} from "@heroicons/react/24/outline";

export default function ShareDialog({ isOpen, onClose, purchaseId }) {
	const [share, setShare] = useState(null);
	const [shareUrl, setShareUrl] = useState("");
	const [ttlHours, setTtlHours] = useState(72);
	const [loading, setLoading] = useState(false);
	const [processing, setProcessing] = useState(false);
	const [error, setError] = useState("");
	const [copied, setCopied] = useState(false);

	useEffect(() => {
		if (!isOpen || !purchaseId) {
			return;
		}

		let cancelled = false;

		setShare(null);
		setShareUrl("");
		setTtlHours(72);
		setError("");
		setCopied(false);
		setLoading(true);

		window.axios
			.post(route("laboratory-purchases.shares.store", { laboratory_purchase: purchaseId }))
			.then(({ data }) => {
				if (cancelled) return;

				setShare(data.share);
				setShareUrl(data.url);
				setTtlHours(data.ttl_hours ?? 72);
			})
			.catch((err) => {
				if (cancelled) return;

				setShare(null);
				setShareUrl("");
				setError(err?.response?.data?.message || "No pudimos preparar el enlace. Intenta de nuevo.");
			})
			.finally(() => {
				if (!cancelled) {
					setLoading(false);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [isOpen, purchaseId]);

	const whatsAppUrl = useMemo(() => (shareUrl ? buildWhatsAppShareUrl(shareUrl) : "#"), [shareUrl]);
	const emailUrl = useMemo(() => (shareUrl ? buildEmailShareUrl(shareUrl) : "#"), [shareUrl]);

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

	const canUseNativeShare = Boolean(shareUrl && typeof window !== "undefined" && window.navigator.share);
	const hasShareReady = Boolean(shareUrl && !loading);
	const showActions = hasShareReady || loading || Boolean(error);

	return (
		<Dialog open={isOpen} onClose={onClose} size="md">
			<DialogTitle>Compartir orden de laboratorio</DialogTitle>
			<DialogDescription>
				Genera un enlace temporal para que un familiar o amigo pueda consultar tu orden de compra.
			</DialogDescription>

			{showActions && (
				<DialogBody className="space-y-4">
					{loading && (
						<div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-200">
							<ArrowPathIcon className="size-4 shrink-0 animate-spin" aria-hidden />
							Preparando enlace para compartir...
						</div>
					)}

					{hasShareReady && (
						<div className="space-y-3">
							<label className="block text-sm font-medium text-zinc-900 dark:text-white">
								Enlace para compartir
							</label>
							<Input readOnly value={shareUrl} onFocus={(event) => event.currentTarget.select()} />
							<div className="space-y-1">
								<p className="text-sm text-zinc-600 dark:text-slate-300">
									Este enlace estara disponible durante {ttlHours} horas.
								</p>
								{share?.formatted_expires_at && (
									<p className="text-sm text-zinc-500 dark:text-slate-400">
										Valido hasta: {share.formatted_expires_at}
									</p>
								)}
							</div>
						</div>
					)}

					{error && (
						<p className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100">
							{error}
						</p>
					)}

					{hasShareReady && (
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
			)}

			<DialogActions className={showActions ? "!mt-6" : "!mt-5"}>
				<Button plain type="button" onClick={onClose}>
					<XMarkIcon data-slot="icon" className="size-4" />
					Cerrar
				</Button>
				{hasShareReady && share?.id && !share?.revoked_at && (
					<Button outline type="button" disabled={processing} onClick={revokeShare}>
						Desactivar enlace
					</Button>
				)}
			</DialogActions>
		</Dialog>
	);
}
