import { ArrowRightIcon, BuildingStorefrontIcon } from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";

function shouldShowBenefit(benefit) {
	if (!benefit?.promotionEnabled) return false;
	if (benefit.hasAssignment) return true;

	return Boolean(benefit.activationEnabled && benefit.hasAvailableCodes);
}

export default function BenavidesBenefitBanner({ benefit }) {
	if (!shouldShowBenefit(benefit)) return null;

	const hasAssignment = Boolean(benefit.hasAssignment);
	const cta = hasAssignment ? "Ver mi credencial" : "Quiero mi beneficio";

	return (
		<section
			aria-label="Beneficio Farmacias Benavides"
			className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:px-6"
		>
			<div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
				<div className="flex min-w-0 gap-4">
					<div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-famedic-dark text-famedic-lime dark:bg-famedic-lime dark:text-famedic-darker">
						<BuildingStorefrontIcon className="size-6" aria-hidden="true" />
					</div>
					<div className="min-w-0">
						<p className="text-xs font-semibold uppercase tracking-[0.08em] text-famedic-dark dark:text-famedic-300">
							FAMEDIC x Farmacias Benavides
						</p>
						<h2 className="mt-1 text-lg font-semibold tracking-normal text-slate-950 dark:text-white">
							Nuevo beneficio en Farmacias Benavides
						</h2>
						<p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
							{hasAssignment
								? "Tu código personal está disponible en tu cuenta FAMEDIC."
								: "Por ser usuario FAMEDIC puedes acceder a los beneficios del convenio con Farmacias Benavides."}
						</p>
						{!hasAssignment && (
							<p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
								Obtén tu código personal y consérvalo en tu cuenta.
							</p>
						)}
					</div>
				</div>

				<Button
					href={route("user.benefits.benavides.show")}
					outline={hasAssignment}
					className="w-full shrink-0 md:w-auto"
				>
					{cta}
					<ArrowRightIcon data-slot="icon" />
				</Button>
			</div>
		</section>
	);
}
