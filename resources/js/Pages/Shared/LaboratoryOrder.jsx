import { Head } from "@inertiajs/react";
import {
	BeakerIcon,
	CalendarDaysIcon,
	ClockIcon,
	InformationCircleIcon,
	MapPinIcon,
} from "@heroicons/react/24/outline";

function Detail({ icon: Icon, label, children }) {
	if (!children) return null;

	return (
		<div className="flex min-w-0 gap-3">
			<Icon className="mt-0.5 size-5 shrink-0 text-sky-700" aria-hidden />
			<div className="min-w-0">
				<p className="text-xs font-semibold uppercase text-zinc-500">{label}</p>
				<div className="mt-1 break-words text-sm text-zinc-900">{children}</div>
			</div>
		</div>
	);
}

export default function SharedLaboratoryOrder({ laboratoryOrder, share }) {
	const appointment = laboratoryOrder?.appointment || {};
	const store = laboratoryOrder?.store;
	const studies = laboratoryOrder?.studies || [];
	const brand = laboratoryOrder?.order?.brand || {};

	return (
		<>
			<Head title="Cita de laboratorio FAMEDIC" />
			<main className="min-h-screen bg-zinc-50 text-zinc-950">
				<div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
					<header className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
						<div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
							<div className="min-w-0">
								<img src="/images/logo.png" alt="FAMEDIC" className="h-9 w-auto" />
								<p className="mt-4 text-sm font-medium text-sky-800">Orden de laboratorio compartida</p>
								<h1 className="mt-2 break-words text-2xl font-semibold text-zinc-950 sm:text-3xl">
									{laboratoryOrder?.patient?.full_name || "Paciente"}
								</h1>
								<p className="mt-2 text-sm text-zinc-600">Folio: {laboratoryOrder?.order?.folio}</p>
							</div>
							{brand.logo_url && (
								<div className="shrink-0 rounded-lg border border-zinc-200 bg-white p-3">
									<img src={brand.logo_url} alt={brand.label || "Laboratorio"} className="h-12 w-auto" />
								</div>
							)}
						</div>
					</header>

					<section className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
						<div className="flex gap-3">
							<InformationCircleIcon className="size-5 shrink-0" aria-hidden />
							<p>{laboratoryOrder?.notice}</p>
						</div>
					</section>

					<section className="grid gap-4 lg:grid-cols-[1.05fr_0.95fr]">
						<div className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
							<h2 className="text-base font-semibold text-zinc-950">Cita</h2>
							<div className="mt-5 space-y-5">
								<Detail icon={CalendarDaysIcon} label="Fecha">
									{appointment.formatted_date || "Pendiente por confirmar"}
								</Detail>
								<Detail icon={ClockIcon} label="Hora">
									{appointment.formatted_time || "Pendiente por confirmar"}
								</Detail>
								<Detail icon={BeakerIcon} label="Laboratorio">
									{brand.label}
								</Detail>
								{share?.formatted_expires_at && (
									<Detail icon={ClockIcon} label="Enlace valido hasta">
										{share.formatted_expires_at}
									</Detail>
								)}
							</div>
						</div>

						<div className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
							<h2 className="text-base font-semibold text-zinc-950">Sucursal</h2>
							{store ? (
								<div className="mt-5 space-y-4">
									<Detail icon={MapPinIcon} label="Nombre">
										{store.name}
									</Detail>
									<Detail icon={MapPinIcon} label="Direccion">
										{store.address}
									</Detail>
									{store.phone && <p className="text-sm text-zinc-700">Telefono: {store.phone}</p>}
									{store.weekly_hours && <p className="text-sm text-zinc-700">Lunes a viernes: {store.weekly_hours}</p>}
									{store.saturday_hours && <p className="text-sm text-zinc-700">Sabado: {store.saturday_hours}</p>}
									{store.sunday_hours && <p className="text-sm text-zinc-700">Domingo: {store.sunday_hours}</p>}
									{store.google_maps_url && (
										<a
											href={store.google_maps_url}
											target="_blank"
											rel="noreferrer noopener"
											className="inline-flex rounded-md bg-sky-700 px-3 py-2 text-sm font-semibold text-white hover:bg-sky-800"
										>
											Como llegar
										</a>
									)}
								</div>
							) : (
								<p className="mt-5 text-sm text-zinc-600">La sucursal esta pendiente por confirmar.</p>
							)}
						</div>
					</section>

					<section className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
						<h2 className="text-base font-semibold text-zinc-950">Estudios e instrucciones</h2>
						<div className="mt-5 divide-y divide-zinc-200">
							{studies.map((study, index) => (
								<article key={`${study.name}-${index}`} className="py-4 first:pt-0 last:pb-0">
									<h3 className="break-words text-sm font-semibold text-zinc-950">{study.name}</h3>
									{study.feature_list?.length > 0 && (
										<ul className="mt-3 grid gap-2 text-sm text-zinc-700 sm:grid-cols-2">
											{study.feature_list.map((feature) => (
												<li key={feature} className="rounded-md bg-zinc-100 px-3 py-2">
													{feature}
												</li>
											))}
										</ul>
									)}
									{study.indications && (
										<p className="mt-3 whitespace-pre-line text-sm text-zinc-700">{study.indications}</p>
									)}
								</article>
							))}
						</div>
					</section>
				</div>
			</main>
		</>
	);
}
