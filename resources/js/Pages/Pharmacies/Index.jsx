import { Head, Link } from "@inertiajs/react";
import {
	ArrowRightIcon,
	ClockIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Button } from "@/Components/Catalyst/button";

const BENAVIDES_HERO = "/images/benefits/benavides/hero.png";
const BENAVIDES_CARD = "/images/benefits/benavides/card-template.jpg";
const BENAVIDES_LOGO = "/images/benefits/benavides/official-logo.png";

export default function PharmaciesIndex() {
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
									<Button href={route("user.benefits.benavides.show")}>
										Ver colaboración
										<ArrowRightIcon data-slot="icon" />
									</Button>
								</div>
							</div>
						</div>

						<div className="flex flex-col justify-between gap-6 p-6 sm:p-8 lg:p-10">
							<div>
								<div className="inline-flex items-center gap-2 rounded-md bg-sky-50 px-3 py-1 text-sm font-medium text-sky-800 dark:bg-sky-400/10 dark:text-sky-200">
									<SparklesIcon className="size-4" aria-hidden="true" />
									Disponible ahora
								</div>
								<img
									src={BENAVIDES_LOGO}
									alt="Farmacias Benavides"
									className="mt-5 h-16 w-full max-w-sm rounded-md bg-white object-contain object-left p-2"
								/>
								<p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
									La primera alianza de farmacia disponible para usuarios FAMEDIC. Tu beneficio queda asociado a tu cuenta y puedes presentarlo desde el celular.
								</p>
							</div>

							<Link
								href={route("user.benefits.benavides.show")}
								className="group block overflow-hidden rounded-lg border border-slate-200 bg-slate-50 transition hover:border-sky-300 hover:bg-white dark:border-slate-800 dark:bg-slate-950/60 dark:hover:border-sky-500/50"
							>
								<div className="grid gap-4 p-4 sm:grid-cols-[120px_minmax(0,1fr)] sm:items-center">
									<img
										src={BENAVIDES_CARD}
										alt=""
										className="h-28 w-full rounded-md object-cover object-top sm:h-32"
									/>
									<div>
										<p className="text-xs font-semibold uppercase tracking-[0.1em] text-sky-700 dark:text-sky-300">
											Credencial digital
										</p>
										<p className="mt-2 text-base font-semibold text-slate-950 dark:text-white">
											Ahorro y bienestar en farmacia
										</p>
										<p className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-400">
											Activa o consulta tu código Benavides.
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
							Por ahora puedes usar la colaboración con Farmacias Benavides desde tu sección de beneficios.
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
