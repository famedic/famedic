import { useEffect, useState } from "react";
import axios from "axios";
import {
	ArrowRightIcon,
	BuildingStorefrontIcon,
	CheckCircleIcon,
	XMarkIcon,
} from "@heroicons/react/20/solid";
import { router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";

export default function BenavidesBenefitModal({ benefit }) {
	const [isOpen, setIsOpen] = useState(Boolean(benefit?.showPromotionModal));
	const [processing, setProcessing] = useState(null);
	const [error, setError] = useState(null);

	useEffect(() => {
		setIsOpen(Boolean(benefit?.showPromotionModal));
	}, [benefit?.showPromotionModal]);

	if (!benefit?.showPromotionModal) return null;

	const dismiss = async () => {
		if (processing) return;

		setProcessing("dismiss");
		setError(null);

		try {
			await axios.post(route("user.benefits.benavides.promotion.dismiss"));
			setIsOpen(false);
		} catch (exception) {
			setError("No pudimos guardar tu decisión. Intenta nuevamente.");
		} finally {
			setProcessing(null);
		}
	};

	const claim = async () => {
		if (processing) return;

		setProcessing("click");
		setError(null);

		try {
			await axios.post(route("user.benefits.benavides.promotion.click"));
		} catch (exception) {
			// El registro del clic no asigna códigos; navegar evita bloquear el acceso al beneficio.
		} finally {
			setIsOpen(false);
			router.visit(route("user.benefits.benavides.show"));
		}
	};

	const handleClose = (nextOpen) => {
		if (nextOpen || processing) return;
		dismiss();
	};

	return (
		<Dialog open={isOpen} onClose={handleClose} size="lg">
			<div className="flex items-start justify-between gap-4">
				<div className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-famedic-dark text-famedic-lime dark:bg-famedic-lime dark:text-famedic-darker">
					<BuildingStorefrontIcon className="size-7" aria-hidden="true" />
				</div>
				<button
					type="button"
					onClick={dismiss}
					disabled={Boolean(processing)}
					aria-label="Ver después"
					className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50 dark:hover:bg-slate-800 dark:hover:text-slate-200"
				>
					<XMarkIcon className="size-5" aria-hidden="true" />
				</button>
			</div>

			<div className="mt-5">
				<p className="text-xs font-semibold uppercase tracking-[0.08em] text-famedic-dark dark:text-famedic-300">
					FAMEDIC + Farmacias Benavides
				</p>
				<DialogTitle className="mt-2 text-2xl/8 sm:text-2xl/8">
					Nuevo beneficio para ti
				</DialogTitle>
				<DialogDescription className="text-sm leading-6">
					Por ser usuario FAMEDIC puedes obtener un código personal para acceder a los
					beneficios aplicables del convenio con Farmacias Benavides.
				</DialogDescription>
			</div>

			<DialogBody>
				<ul className="space-y-3 text-sm leading-6 text-slate-700 dark:text-slate-300">
					{[
						"Tu código queda guardado en tu cuenta.",
						"Puedes consultarlo después desde Mis beneficios.",
						"Podrás mostrarlo directamente desde tu celular.",
					].map((item) => (
						<li key={item} className="flex gap-3">
							<CheckCircleIcon
								className="mt-0.5 size-5 shrink-0 text-famedic-dark dark:text-famedic-300"
								aria-hidden="true"
							/>
							<span>{item}</span>
						</li>
					))}
				</ul>

				{error && (
					<p className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
						{error}
					</p>
				)}
			</DialogBody>

			<DialogActions>
				<Button type="button" outline onClick={dismiss} disabled={Boolean(processing)}>
					{processing === "dismiss" ? "Guardando..." : "Ver después"}
				</Button>
				<Button type="button" onClick={claim} disabled={Boolean(processing)}>
					{processing === "click" ? "Abriendo..." : "Quiero mi beneficio"}
					<ArrowRightIcon data-slot="icon" />
				</Button>
			</DialogActions>
		</Dialog>
	);
}
