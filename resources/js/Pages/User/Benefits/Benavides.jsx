import { Head, useForm } from "@inertiajs/react";
import {
	CheckCircleIcon,
	ExclamationCircleIcon,
	GiftIcon,
	ShieldCheckIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";
import BenavidesCredential from "@/Components/Benefits/BenavidesCredential";
import { Button } from "@/Components/Catalyst/button";
import SettingsLayout from "@/Layouts/SettingsLayout";

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
	return (
		<section className="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
			<h2 className="text-base font-semibold text-slate-950 dark:text-white">
				Cómo usar tu beneficio
			</h2>
			<ul className="mt-4 space-y-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
				<li>Muestra tu credencial o dicta el código cuando te lo soliciten en farmacia.</li>
				<li>El personal de Farmacias Benavides validará el código de acuerdo con sus procesos vigentes.</li>
				<li>Conserva esta pantalla para futuras consultas desde tu cuenta FAMEDIC.</li>
			</ul>
		</section>
	);
}

function ActivationState({ processing, onActivate }) {
	return (
		<div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)] lg:items-start">
			<section className="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6">
				<p className="text-sm font-semibold uppercase tracking-[0.08em] text-famedic-dark dark:text-famedic-300">
					FAMEDIC x Farmacias Benavides
				</p>
				<h1 className="mt-3 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white sm:text-3xl">
					Nuevo beneficio para ti
				</h1>
				<p className="mt-4 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
					Activa tu credencial FAMEDIC para recibir un código personal de Farmacias Benavides.
					La activación es explícita y el código quedará asociado a tu cuenta.
				</p>

				<div className="mt-6 grid gap-3 sm:grid-cols-3">
					{[
						["Código personal", "Asignado a tu cuenta FAMEDIC."],
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

				<form onSubmit={onActivate} className="mt-7">
					<Button type="submit" disabled={processing} className="w-full sm:w-auto">
						<SparklesIcon data-slot="icon" />
						{processing ? "Activando..." : "Activar mi beneficio"}
					</Button>
				</form>
			</section>

			<InfoPanel icon={ShieldCheckIcon} title="Antes de activar">
				<p>
					El beneficio se asigna por disponibilidad de códigos. Si la disponibilidad se agota durante
					el proceso, te mostraremos un mensaje claro y podrás volver a consultar esta sección.
				</p>
			</InfoPanel>
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
