import { Head, Link } from "@inertiajs/react";
import {
	ArrowRightIcon,
	ClockIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Button } from "@/Components/Catalyst/button";

const BENAVIDES_HERO = "/images/benefits/benavides/hero.png";
const BENAVIDES_CARD = "/images/benefits/benavides/card-template-integrated.png";

export default function PharmaciesIndex({ benavidesBenefit = {} }) {
	const hasActiveBenefit = Boolean(benavidesBenefit.hasAssignment);
	const canActivateBenefit = Boolean(
		benavidesBenefit.activationEnabled && benavidesBenefit.hasAvailableCodes,
	);
	const benefitTitle = hasActiveBenefit
		? "Disfruta tu beneficio Benavides"
		: "Activa tu beneficio Benavides";
	const benefitDescription = hasActiveBenefit
		? "Tu credencial ya está activa. Consulta tu código cuando visites Farmacias Benavides y preséntalo desde tu celular."
		: canActivateBenefit
			? "Activa tu credencial digital y conserva tu código personal para usarlo cuando visites Farmacias Benavides."
			: "La colaboración está disponible en FAMEDIC. Vuelve pronto para activar tu credencial cuando haya códigos disponibles.";
	const credentialHint = hasActiveBenefit
		? "Consulta tu código Benavides y úsalo en farmacia."
		: canActivateBenefit
			? "Activa tu código Benavides en minutos."
			: "Revisa el estado de disponibilidad del beneficio.";

	return (
		<FamedicLayout title="Farmacias">
			<Head title="Farmacias" />

			<div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
				<section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
					<div className="grid lg:grid-cols-[minmax(0,1fr)_420px]">
						<div className="relative min-h-[340px] overflow-hidden bg-slate-900 lg:min-h-[460px]">
							<img
								src={BENAVIDES_HERO}
								alt="Farmacias Benavides"
								className="absolute inset-0 h-full w-full object-cover"
							/>
							<div className="absolute inset-0 bg-gradient-to-r from-slate-950/70 via-slate-950/20 to-transparent" />
							<div className="relative flex h-full max-w-2xl flex-col justify-end p-6 text-white sm:p-8 lg:p-10">
								<p className="text-xs font-semibold uppercase tracking-[0.12em] text-sky-100">
									FAMEDIC x Farmacias Benavides
								</p>
								<h1 className="mt-3 max-w-xl text-3xl font-semibold tracking-normal sm:text-4xl">
									Beneficios de farmacia desde tu cuenta FAMEDIC
								</h1>
								<p className="mt-4 max-w-lg text-sm leading-6 text-white/85 sm:text-base">
									Activa tu credencial de Benavides y conserva tu código personal para consultarlo cuando lo necesites.
								</p>
								<div className="mt-6">
									<Button
										href={route("user.benefits.benavides.show")}
										className="px-5 py-3 text-sm font-semibold shadow-lg shadow-slate-950/20"
									>
										Aprovechar beneficio
										<ArrowRightIcon data-slot="icon" />
									</Button>
								</div>
							</div>
						</div>

						<div className="flex flex-col justify-between gap-6 p-6 sm:p-8 lg:p-10">
							<div>
								<div className="inline-flex items-center gap-2 rounded-md bg-sky-50 px-3 py-1 text-sm font-medium text-sky-800 dark:bg-sky-400/10 dark:text-sky-200">
									<SparklesIcon className="size-4" aria-hidden="true" />
									{hasActiveBenefit ? "Beneficio activo" : "Disponible ahora"}
								</div>
								<h2 className="mt-5 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">
									{benefitTitle}
								</h2>
								<p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
									{benefitDescription}
								</p>
							</div>

							<Link
								href={route("user.benefits.benavides.show")}
								className="group block overflow-hidden rounded-lg border border-slate-200 bg-slate-50 transition hover:border-sky-300 hover:bg-white dark:border-slate-800 dark:bg-slate-950/60 dark:hover:border-sky-500/50"
							>
								<div className="grid gap-5 p-4 sm:grid-cols-[150px_minmax(0,1fr)] sm:items-center">
									<div className="overflow-hidden rounded-md border border-sky-100 bg-white">
										<img
											src={BENAVIDES_CARD}
											alt="Credencial digital FAMEDIC y Farmacias Benavides"
											className="h-40 w-full object-contain p-2 sm:h-44"
										/>
									</div>
									<div>
										<p className="text-xs font-semibold uppercase tracking-[0.1em] text-sky-700 dark:text-sky-300">
											Credencial digital
										</p>
										<p className="mt-2 text-base font-semibold text-slate-950 dark:text-white">
											Ahorro y bienestar en farmacia
										</p>
										<p className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-400">
											{credentialHint}
										</p>
									</div>
								</div>
							</Link>
						</div>
					</div>
				</section>

				<section className="grid gap-4 md:grid-cols-2">
					<div className="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
						<h2 className="text-lg font-semibold text-slate-950 dark:text-white">
							Colaboraciones disponibles
						</h2>
						<p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
							{hasActiveBenefit
								? "Tu colaboración con Farmacias Benavides está activa y disponible desde tu sección de beneficios."
								: "Por ahora puedes activar la colaboración con Farmacias Benavides desde tu sección de beneficios."}
						</p>
					</div>
					<div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 dark:border-slate-700 dark:bg-slate-900/60">
						<div className="flex items-start gap-3">
							<ClockIcon className="mt-0.5 size-5 shrink-0 text-slate-500" aria-hidden="true" />
							<div>
								<h2 className="text-lg font-semibold text-slate-950 dark:text-white">
									Próximamente más marcas y alianzas
								</h2>
								<p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
									Estamos preparando nuevas colaboraciones para ampliar los beneficios disponibles dentro de FAMEDIC.
								</p>
							</div>
						</div>
					</div>
				</section>
			</div>
		</FamedicLayout>
	);
}
