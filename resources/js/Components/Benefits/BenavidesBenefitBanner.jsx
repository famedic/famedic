import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";

const BENAVIDES_HERO = "/images/benefits/benavides/hero.png";
const BENAVIDES_CARD = "/images/benefits/benavides/card-template.jpg";
const BENAVIDES_LOGO = "/images/benefits/benavides/official-logo.png";
const FAMEDIC_LOGO = "/images/logo.png";

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
			className="mt-6 overflow-hidden rounded-lg border border-sky-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
		>
			<div className="grid md:grid-cols-[280px_minmax(0,1fr)]">
				<div className="relative min-h-44 overflow-hidden bg-[#25539b] md:min-h-full">
					<img
						src={BENAVIDES_HERO}
						alt=""
						className="absolute inset-0 h-full w-full object-cover object-center opacity-80 md:object-[58%_center]"
					/>
					<div className="absolute inset-0 bg-gradient-to-r from-[#25539b]/90 via-[#25539b]/50 to-transparent" />
					<div className="relative flex h-full min-h-44 flex-col justify-between p-4 text-white">
						<img
							src={BENAVIDES_LOGO}
							alt="Farmacias Benavides"
							className="h-10 w-48 rounded-md bg-white object-contain object-left p-2 shadow-sm"
						/>
						<div>
							<p className="text-xs font-semibold uppercase tracking-[0.12em] text-white/85">
								Colaboración oficial
							</p>
							<p className="mt-1 text-xl font-semibold leading-tight">
								Ahorro y bienestar en farmacia
							</p>
						</div>
					</div>
				</div>
				<div className="grid gap-4 px-4 py-4 sm:px-6 md:grid-cols-[minmax(0,1fr)_180px_auto] md:items-center">
					<div className="min-w-0">
						<div className="flex flex-wrap items-center gap-3">
							<div className="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-2 shadow-sm">
								<img
									src={FAMEDIC_LOGO}
									alt="Famedic"
									className="h-6 w-24 object-contain object-left"
								/>
							</div>
							<span className="text-sm font-semibold text-slate-400">+</span>
							<div className="inline-flex items-center gap-2 rounded-md border border-sky-100 bg-sky-50 px-3 py-2">
								<img
									src={BENAVIDES_LOGO}
									alt="Farmacias Benavides"
									className="h-6 w-36 object-contain object-left"
								/>
							</div>
						</div>
						<h2 className="mt-3 text-lg font-semibold tracking-normal text-slate-950 dark:text-white">
							{hasAssignment
								? "Tu credencial Benavides ya está activa"
								: "Activa tu beneficio Benavides"}
						</h2>
						<p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
							{hasAssignment
								? "Tu código personal de Farmacias Benavides está guardado en tu cuenta FAMEDIC."
								: "Obtén tu código personal de la alianza FAMEDIC + Farmacias Benavides y consúltalo desde tu cuenta."}
						</p>
					</div>

					<div className="hidden overflow-hidden rounded-md border border-sky-100 bg-sky-50 md:block">
						<img
							src={BENAVIDES_CARD}
							alt=""
							className="h-24 w-full object-cover object-top"
						/>
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
			</div>
		</section>
	);
}
