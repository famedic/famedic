import { Head, useForm } from "@inertiajs/react";
import {
	ChevronDownIcon,
	CheckCircleIcon,
	ExclamationCircleIcon,
	GiftIcon,
	ShieldCheckIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";
import BenavidesCredential from "@/Components/Benefits/BenavidesCredential";
import { Button } from "@/Components/Catalyst/button";
import SettingsLayout from "@/Layouts/SettingsLayout";

const BENAVIDES_HERO = "/images/benefits/benavides/hero.png";
const BENAVIDES_LOGO = "/images/benefits/benavides/official-logo.png";

function InfoPanel({ icon: Icon, title, children, tone = "neutral" }) {
	const tones = {
		neutral:
			"border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300",
		warning:
			"border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100",
		disabled:
			"border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-300",
	};

	return (
		<div className={`rounded-lg border p-5 ${tones[tone]}`}>
			<div className="flex gap-3">
				<Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
				<div>
					<h2 className="text-base font-semibold text-slate-950 dark:text-white">{title}</h2>
					<div className="mt-2 text-sm leading-6">{children}</div>
				</div>
			</div>
		</div>
	);
}

function Instructions() {
	const steps = [
		[
			"Muestra tu credencial",
			"Presenta el código de barras en farmacia o usa el reverso si te piden dictarlo.",
		],
		[
			"Validación en Benavides",
			"El personal de Farmacias Benavides validará el código según sus procesos vigentes.",
		],
		[
			"Consúltala cuando quieras",
			"Esta credencial queda disponible en Mis beneficios para futuras visitas.",
		],
	];

	return (
		<section className="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
			<h2 className="text-base font-semibold text-slate-950 dark:text-white">
				Cómo usar tu beneficio
			</h2>
			<ul className="mt-5 space-y-4">
				{steps.map(([title, text], index) => (
					<li key={title} className="flex gap-3">
						<span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-famedic-dark text-sm font-semibold text-white dark:bg-famedic-300 dark:text-slate-950">
							{index + 1}
						</span>
						<div>
							<h3 className="text-sm font-semibold text-slate-950 dark:text-white">{title}</h3>
							<p className="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{text}</p>
						</div>
					</li>
				))}
			</ul>
		</section>
	);
}

function ActivationState({ processing, onActivate }) {
	return (
		<div className="space-y-4">
			<section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
				<div className="relative min-h-64 overflow-hidden bg-slate-950 sm:min-h-80">
					<img
						src={BENAVIDES_HERO}
						alt="Farmacias Benavides"
						className="absolute inset-0 h-full w-full object-cover object-center"
					/>
					<div className="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-950/50 to-slate-950/20" />
					<div className="relative flex min-h-64 max-w-2xl flex-col justify-end p-5 text-white sm:min-h-80 sm:p-6">
						<div className="mb-5 inline-flex w-fit items-center rounded-md bg-white/95 px-3 py-2 shadow-sm">
							<img
								src={BENAVIDES_LOGO}
								alt="Farmacias Benavides"
								className="h-8 w-52 object-contain object-left"
							/>
						</div>
						<h1 className="max-w-lg text-2xl font-semibold tracking-normal drop-shadow-sm sm:text-3xl">
							Tu beneficio Benavides
						</h1>
						<p className="mt-4 max-w-lg rounded-md bg-slate-950/55 p-3 text-sm leading-6 text-white shadow-sm ring-1 ring-white/10 backdrop-blur-[2px] sm:text-base">
							Activa tu credencial para recibir un código personal y consultarlo desde tu cuenta cuando lo necesites.
						</p>
					</div>
				</div>

				<div className="grid gap-3 p-5 sm:grid-cols-3 sm:p-6">
					{[
						["Código personal", "Asignado a tu cuenta."],
						["Consulta permanente", "Disponible desde Mis beneficios."],
						["Uso simple", "Muestra el código cuando te lo soliciten."],
					].map(([title, text]) => (
						<div
							key={title}
							className="rounded-lg border border-slate-200 p-4 dark:border-slate-800"
						>
							<CheckCircleIcon className="size-5 text-famedic-dark dark:text-famedic-300" />
							<h2 className="mt-3 text-sm font-semibold text-slate-950 dark:text-white">
								{title}
							</h2>
							<p className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-400">{text}</p>
						</div>
					))}
				</div>

				<form onSubmit={onActivate} className="px-5 pb-5 sm:px-6 sm:pb-6">
					<Button type="submit" disabled={processing} className="w-full sm:w-auto">
						<SparklesIcon data-slot="icon" />
						{processing ? "Activando..." : "Activar mi beneficio"}
					</Button>
				</form>
			</section>

			<details className="group rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
				<summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-950 marker:hidden dark:text-white">
					<span className="inline-flex items-center gap-2">
						<ShieldCheckIcon className="size-5 text-slate-500 dark:text-slate-400" aria-hidden="true" />
						Antes de activar
					</span>
					<ChevronDownIcon
						className="size-5 text-slate-400 transition group-open:rotate-180"
						aria-hidden="true"
					/>
				</summary>
				<div className="border-t border-slate-100 px-4 pb-4 pt-3 text-sm leading-6 text-slate-600 dark:border-slate-800 dark:text-slate-300">
					El beneficio se asigna por disponibilidad de códigos. Si la disponibilidad se agota durante
					el proceso, te mostraremos un mensaje claro y podrás volver a consultar esta sección.
				</div>
			</details>
		</div>
	);
}

export default function Benavides({
	assignment,
	benefitEnabled,
	hasAvailableCodes,
	holderName,
}) {
	const { post, processing } = useForm();

	const activate = (event) => {
		event.preventDefault();

		if (processing) return;

		post(route("user.benefits.benavides.activate"), {
			preserveScroll: true,
		});
	};

	let content;

	if (assignment) {
		content = (
			<div className="grid gap-6 lg:grid-cols-[minmax(320px,420px)_minmax(0,1fr)] lg:items-start">
				<BenavidesCredential
					code={assignment.code}
					holderName={holderName}
					assignedAt={assignment.assigned_at}
				/>
				<Instructions />
			</div>
		);
	} else if (!benefitEnabled) {
		content = (
			<InfoPanel icon={ExclamationCircleIcon} title="Beneficio no disponible" tone="disabled">
				<p>
					Este beneficio no está disponible en este momento. Si ya contabas con una credencial,
					seguiría visible en esta sección.
				</p>
			</InfoPanel>
		);
	} else if (!hasAvailableCodes) {
		content = (
			<InfoPanel icon={GiftIcon} title="Códigos agotados por ahora" tone="warning">
				<p>
					Los códigos disponibles para Farmacias Benavides se agotaron temporalmente. Cuando exista
					nueva disponibilidad, podrás volver a activar tu beneficio desde esta sección.
				</p>
			</InfoPanel>
		);
	} else {
		content = <ActivationState processing={processing} onActivate={activate} />;
	}

	return (
		<SettingsLayout title="Mis beneficios" hideHelpBubble>
			<Head title="Mis beneficios" />
			<div className="space-y-6">
				<div>
					<p className="text-sm font-medium text-slate-500 dark:text-slate-400">
						Farmacias Benavides
					</p>
					<h1 className="mt-1 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">
						Mis beneficios
					</h1>
				</div>
				{content}
			</div>
		</SettingsLayout>
	);
}
