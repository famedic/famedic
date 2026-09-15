import { useState } from "react";
import { Head, usePage } from "@inertiajs/react";
import ApplicationLogo from "@/Components/ApplicationLogo";
import { WhatsAppIcon } from "@/Components/Checkout/CheckoutWhatsAppHelp";
import {
	BeakerIcon,
	BuildingStorefrontIcon,
	CalendarDaysIcon,
	CheckCircleIcon,
	ChevronDownIcon,
	ClipboardDocumentCheckIcon,
	ClockIcon,
	CurrencyDollarIcon,
	IdentificationIcon,
	MapPinIcon,
	PhoneIcon,
	QueueListIcon,
	UserCircleIcon,
} from "@heroicons/react/24/outline";

function Section({ title, icon: Icon, action, children, className = "" }) {
	return (
		<section className={`rounded-lg border border-zinc-200 bg-white p-5 shadow-sm ${className}`}>
			<div className="flex min-w-0 flex-col items-start justify-between gap-4 sm:flex-row">
				<div className="flex min-w-0 items-center gap-3">
					{Icon && (
						<span className="grid size-9 shrink-0 place-items-center rounded-md bg-sky-50 text-[#005e96]">
							<Icon className="size-5" aria-hidden />
						</span>
					)}
					<h2 className="min-w-0 break-words text-base font-semibold text-[#171f45]">{title}</h2>
				</div>
				{action}
			</div>
			<div className="mt-4">{children}</div>
		</section>
	);
}

function Detail({ icon: Icon, label, children }) {
	if (children === null || children === undefined || children === "") return null;

	return (
		<div className="flex min-w-0 gap-3">
			<Icon className="mt-0.5 size-5 shrink-0 text-[#005e96]" aria-hidden />
			<div className="min-w-0">
				<p className="text-xs font-semibold uppercase text-zinc-500">{label}</p>
				<div className="mt-1 break-words text-sm text-zinc-900">{children}</div>
			</div>
		</div>
	);
}

function Row({ label, value, strong = false, negative = false }) {
	if (!value) return null;

	return (
		<div className="flex min-w-0 items-baseline justify-between gap-4 py-2 text-sm">
			<span className={strong ? "font-semibold text-[#171f45]" : "text-zinc-600"}>{label}</span>
			<span
				className={`min-w-0 text-right tabular-nums ${
					strong ? "text-lg font-bold text-[#171f45]" : negative ? "font-medium text-emerald-700" : "text-zinc-900"
				}`}
			>
				{negative ? "-" : ""}
				{value}
			</span>
		</div>
	);
}

function StatusBadge({ appointment }) {
	const requiresAppointment = Boolean(appointment?.requires_appointment);
	const hasAppointment = Boolean(appointment?.has_appointment);
	const text = hasAppointment ? "Cita programada" : requiresAppointment ? "Cita pendiente" : "No requiere cita";
	const tone = hasAppointment
		? "bg-lime-100 text-lime-950"
		: requiresAppointment
			? "bg-amber-100 text-amber-900"
			: "bg-emerald-100 text-emerald-900";

	return <span className={`inline-flex rounded-md px-2.5 py-1 text-xs font-semibold ${tone}`}>{text}</span>;
}

function PrimaryLink({ href, children, outline = false, external = false, icon: Icon = null }) {
	if (!href) return null;

	const className = outline
		? "inline-flex min-h-11 w-full items-center justify-center rounded-md border border-zinc-300 px-4 py-2 text-sm font-semibold text-[#171f45] transition hover:border-[#005e96] hover:bg-sky-50 sm:w-auto"
		: "inline-flex min-h-11 w-full items-center justify-center rounded-md bg-[#005e96] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#004d7c] sm:w-auto";

	return (
		<a href={href} target={external ? "_blank" : undefined} rel={external ? "noreferrer noopener" : undefined} className={className}>
			{Icon && <Icon className="mr-2 size-4 shrink-0" aria-hidden />}
			{children}
		</a>
	);
}

function compactMoney(value) {
	return value ? String(value).replace(/\s?MXN$/u, "") : value;
}

function formatStoresCount(count) {
	const safeCount = Number.isFinite(Number(count)) ? Number(count) : 0;

	return `${safeCount} ${safeCount === 1 ? "sucursal disponible" : "sucursales disponibles"}`;
}

function normalizePersonName(value) {
	return String(value || "").trim().replace(/\s+/gu, " ").toLowerCase();
}

function buildSupportWhatsAppUrl() {
	return `https://wa.me/528128601893?text=${encodeURIComponent("Hola, necesito ayuda con mi orden de laboratorio Famedic.")}`;
}

function buildAbsoluteUrl(path, currentUrl) {
	if (!path || /^https?:\/\//iu.test(path)) return path;
	if (!currentUrl) return path;

	return new URL(path, currentUrl).toString();
}

function IndicationsAccordion({ studies }) {
	const firstWithIndications = studies.findIndex((study) => Boolean(study.indications));
	const [openIndexes, setOpenIndexes] = useState(firstWithIndications >= 0 ? [firstWithIndications] : []);
	const indicationIndexes = studies
		.map((study, index) => (study.indications ? index : null))
		.filter((index) => index !== null);
	const showGlobalControls = indicationIndexes.length >= 10;

	function toggleIndex(index) {
		setOpenIndexes((current) =>
			current.includes(index)
				? current.filter((openIndex) => openIndex !== index)
				: [...current, index],
		);
	}

	return (
		<div className="space-y-3">
			{showGlobalControls && (
				<div className="flex flex-wrap justify-end gap-2">
					<button
						type="button"
						className="min-h-10 rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100"
						onClick={() => setOpenIndexes(indicationIndexes)}
					>
						Expandir todas
					</button>
					<button
						type="button"
						className="min-h-10 rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100"
						onClick={() => setOpenIndexes([])}
					>
						Contraer todas
					</button>
				</div>
			)}
			<div className="divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200">
				{studies.map((study, index) => {
					const isOpen = openIndexes.includes(index);
					const panelId = `study-indications-${index}`;

					return (
						<div key={`indications-${study.name}-${index}`} className="bg-white">
							<button
								type="button"
								className="flex min-h-12 w-full min-w-0 flex-col gap-2 px-4 py-3 text-left sm:flex-row sm:items-center sm:justify-between"
								aria-expanded={isOpen}
								aria-controls={panelId}
								onClick={() => toggleIndex(index)}
							>
								<span className="min-w-0 break-words text-sm font-semibold text-zinc-950">{study.name}</span>
								<span className="inline-flex shrink-0 items-center gap-2 text-sm font-semibold text-sky-700">
									{isOpen ? "Ocultar indicaciones" : "Ver indicaciones"}
									<ChevronDownIcon className={`size-5 text-zinc-500 transition ${isOpen ? "rotate-180" : ""}`} aria-hidden />
								</span>
							</button>
							{isOpen && (
								<div id={panelId} className="border-t border-zinc-100 px-4 py-4">
									{study.indications ? (
										<p className="whitespace-pre-line break-words text-sm leading-6 text-zinc-800">{study.indications}</p>
									) : (
										<p className="text-sm text-zinc-600">Sin indicaciones registradas para este estudio.</p>
									)}
								</div>
							)}
						</div>
					);
				})}
			</div>
		</div>
	);
}

function CheckLine({ children }) {
	return (
		<li className="flex gap-2">
			<CheckCircleIcon className="mt-0.5 size-5 shrink-0 text-lime-600" aria-hidden />
			<span>{children}</span>
		</li>
	);
}

function OperationalDatum({ label, value }) {
	if (!value) return null;

	return (
		<div className="rounded-md bg-zinc-50 px-3 py-2">
			<p className="text-[11px] font-semibold uppercase text-zinc-500">{label}</p>
			<p className={`mt-1 break-words font-semibold text-zinc-950 ${["Folio", "Consecutivo"].includes(label) ? "text-lg" : "text-base"}`}>{value}</p>
		</div>
	);
}

function FeaturedStoreCard({ store }) {
	return (
		<article className="flex min-w-0 flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-4">
			<div className="min-w-0">
				<h3 className="break-words text-sm font-semibold text-zinc-950">{store.name}</h3>
				{store.address && <p className="mt-2 break-words text-sm leading-6 text-zinc-700">{store.address}</p>}
			</div>
			<div className="space-y-2 text-sm text-zinc-700">
				{store.hours && (
					<p className="flex gap-2">
						<ClockIcon className="mt-0.5 size-4 shrink-0 text-[#005e96]" aria-hidden />
						<span className="break-words">{store.hours}</span>
					</p>
				)}
				{store.phone && (
					<p className="flex gap-2 text-zinc-500">
						<PhoneIcon className="mt-0.5 size-4 shrink-0 text-zinc-400" aria-hidden />
						<span className="break-all">{store.phone}</span>
					</p>
				)}
			</div>
			<div className="mt-auto">
				<PrimaryLink href={store.google_maps_url} external outline icon={MapPinIcon}>
					Cómo llegar
				</PrimaryLink>
			</div>
		</article>
	);
}

function AppointmentBoardingPass({ appointment, brand, store, share, storeDirectoryUrl }) {
	return (
		<Section title="Tu cita" icon={CalendarDaysIcon}>
			<div className="grid gap-5 lg:grid-cols-[0.7fr_1.3fr]">
				<div className="rounded-lg border border-[#e6e1f7] border-l-4 border-l-[#5944b5] bg-[#f7f5ff] p-4">
					<p className="text-xs font-semibold uppercase text-[#5944b5]">Tu cita está programada en esta sucursal</p>
					<p className="mt-3 break-words text-2xl font-semibold text-[#171f45]">{appointment.formatted_date}</p>
					{appointment.formatted_time && (
						<p className="mt-2 flex items-center gap-2 text-xl font-semibold text-[#5944b5]">
							<ClockIcon className="size-6 text-[#5944b5]" aria-hidden />
							{appointment.formatted_time}
						</p>
					)}
					{share?.formatted_expires_at && (
						<p className="mt-4 text-xs leading-5 text-zinc-600">Enlace válido hasta {share.formatted_expires_at}.</p>
					)}
				</div>
				<div className="space-y-4">
					<div>
						<p className="text-xs font-semibold uppercase text-zinc-500">{brand.label || "Laboratorio"}</p>
						<h3 className="mt-1 break-words text-xl font-semibold text-[#171f45]">{store?.name}</h3>
						{store?.address && <p className="mt-2 break-words text-sm leading-6 text-zinc-700">{store.address}</p>}
					</div>
					<div className="grid gap-3 text-sm text-zinc-700 sm:grid-cols-3">
						{store?.weekly_hours && <p>Lun-vie: {store.weekly_hours}</p>}
						{store?.saturday_hours && <p>Sábado: {store.saturday_hours}</p>}
						{store?.sunday_hours && <p>Domingo: {store.sunday_hours}</p>}
					</div>
					{store?.phone && (
						<p className="flex gap-2 text-sm text-zinc-500">
							<PhoneIcon className="mt-0.5 size-4 shrink-0 text-zinc-400" aria-hidden />
							<span className="break-all">{store.phone}</span>
						</p>
					)}
					<div className="flex flex-wrap gap-3">
						<PrimaryLink href={store?.google_maps_url} external icon={MapPinIcon}>
							Cómo llegar
						</PrimaryLink>
						<PrimaryLink href={storeDirectoryUrl} outline>
							Ver otras sucursales
						</PrimaryLink>
					</div>
					<p className="text-xs leading-5 text-zinc-500">Para acudir a otra sucursal, confirma primero el cambio de cita.</p>
				</div>
			</div>
		</Section>
	);
}

function NoAppointmentStores({ brand, featuredStores, storeDirectoryUrl }) {
	return (
		<Section
			title="Tus estudios no requieren cita previa"
			icon={BuildingStorefrontIcon}
			action={
				<PrimaryLink href={storeDirectoryUrl} outline>
					Ver todas las {brand.stores_count} sucursales
				</PrimaryLink>
			}
		>
			<div className="space-y-5">
				<div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
					<div className="min-w-0">
						<p className="break-words text-sm text-zinc-700">
							Puedes acudir a cualquiera de las sucursales disponibles de {brand.label || "tu laboratorio"} dentro de su horario de atención.
						</p>
						<p className="mt-2 text-sm font-semibold text-zinc-950">{formatStoresCount(brand.stores_count)}</p>
					</div>
				</div>
				{featuredStores.length > 0 && (
					<div className="grid gap-4 md:grid-cols-2">
						{featuredStores.map((featuredStore, index) => (
							<FeaturedStoreCard key={`${featuredStore.name}-${index}`} store={featuredStore} />
						))}
					</div>
				)}
			</div>
		</Section>
	);
}

function GuideStep({ number, icon: Icon, title, children }) {
	return (
		<div className="flex min-w-0 gap-3 rounded-lg border border-zinc-200 bg-white p-4">
			<span className="grid size-8 shrink-0 place-items-center rounded-md bg-zinc-950 text-sm font-semibold text-white">{number}</span>
			<div className="min-w-0">
				<div className="flex min-w-0 items-center gap-2">
					<Icon className="size-5 shrink-0 text-sky-700" aria-hidden />
					<h3 className="min-w-0 break-words text-sm font-semibold text-zinc-950">{title}</h3>
				</div>
				<div className="mt-2 break-words text-sm leading-6 text-zinc-700">{children}</div>
			</div>
		</div>
	);
}

export default function SharedLaboratoryOrder({ laboratoryOrder, share }) {
	const { props } = usePage();
	const appointment = laboratoryOrder?.appointment || {};
	const store = laboratoryOrder?.store;
	const studies = laboratoryOrder?.studies || [];
	const brand = laboratoryOrder?.order?.brand || {};
	const patient = laboratoryOrder?.patient || {};
	const purchaser = laboratoryOrder?.purchaser || {};
	const order = laboratoryOrder?.order || {};
	const pricing = laboratoryOrder?.pricing || {};
	const featuredStores = brand.featured_stores || [];
	const hasAppointment = Boolean(appointment.has_appointment);
	const requiresAppointment = Boolean(appointment.requires_appointment);
	const hasConsecutive = Boolean(order.consecutive);
	const storeDirectoryUrl = brand.stores_url;
	const samePurchaserAndPatient =
		normalizePersonName(purchaser.name) !== "" &&
		normalizePersonName(purchaser.name) === normalizePersonName(patient.full_name);
	const showSecondFolio = order.laboratory_order_folio && order.laboratory_order_folio !== order.famedic_folio;
	const appointmentCard = hasAppointment ? (
		<AppointmentBoardingPass
			appointment={appointment}
			brand={brand}
			store={store}
			share={share}
			storeDirectoryUrl={storeDirectoryUrl}
		/>
	) : requiresAppointment ? (
		<Section title="Cita pendiente" icon={BuildingStorefrontIcon}>
			<div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
				<div className="min-w-0">
					<p className="break-words text-sm text-zinc-700">
						Tus estudios requieren cita. Revisa las sucursales de {brand.label || "tu laboratorio"} para continuar.
					</p>
					<p className="mt-2 text-sm font-semibold text-zinc-950">{formatStoresCount(brand.stores_count)}</p>
				</div>
				<PrimaryLink href={storeDirectoryUrl}>Ver sucursales</PrimaryLink>
			</div>
		</Section>
	) : null;
	const patientAndOrderCards = (
		<div className="grid gap-5 lg:grid-cols-[1.05fr_0.95fr]">
			<Section title="Paciente y titular" icon={UserCircleIcon}>
				<div className="grid gap-4 sm:grid-cols-2">
					<Detail icon={IdentificationIcon} label="Paciente">
						{patient.full_name || "Paciente"}
					</Detail>
					<Detail icon={UserCircleIcon} label="Titular de la compra">
						{samePurchaserAndPatient ? "Mismo paciente" : purchaser.name}
					</Detail>
					<Detail icon={CalendarDaysIcon} label="Fecha de nacimiento">
						{patient.formatted_birth_date}
					</Detail>
					<Detail icon={IdentificationIcon} label="Género">
						{patient.formatted_gender}
					</Detail>
				</div>
			</Section>

			<Section title="Datos de tu orden" icon={QueueListIcon}>
				<div className="grid gap-4 sm:grid-cols-2">
					<Detail icon={QueueListIcon} label="Folio operativo">
						{order.famedic_folio || order.folio}
					</Detail>
					{showSecondFolio && (
						<Detail icon={QueueListIcon} label="Folio de laboratorio">
							{order.laboratory_order_folio}
						</Detail>
					)}
					<Detail icon={QueueListIcon} label="Consecutivo">
						{order.consecutive}
					</Detail>
					<Detail icon={BeakerIcon} label="Laboratorio">
						{brand.label}
					</Detail>
				</div>
			</Section>
		</div>
	);
	const studiesAndSummaryCards = (
		<div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
			<Section title="Estudios solicitados" icon={BeakerIcon}>
				<div className="divide-y divide-zinc-200">
					{studies.map((study, index) => (
						<article key={`${study.name}-${index}`} className="py-4 first:pt-0 last:pb-0">
							<div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
								<div className="min-w-0">
									<h3 className="break-words text-sm font-semibold text-zinc-950">{study.name}</h3>
									{study.feature_list?.length > 0 && (
										<ul className="mt-3 flex min-w-0 flex-wrap gap-2 text-xs text-zinc-700">
											{study.feature_list.map((feature) => (
												<li key={feature} className="rounded-md bg-zinc-100 px-3 py-2">
													{feature}
												</li>
											))}
										</ul>
									)}
								</div>
								{study.price && (
									<p className="shrink-0 whitespace-nowrap text-sm font-semibold tabular-nums text-zinc-950">
										{compactMoney(study.price)}
									</p>
								)}
							</div>
						</article>
					))}
				</div>
			</Section>

			<Section title="Resumen de orden" icon={CurrencyDollarIcon}>
				<div className="divide-y divide-zinc-100">
					<Row label="Subtotal" value={pricing.subtotal} />
					<Row label="Cupón aplicado" value={pricing.coupon_discount} negative />
					<div className="mt-3 rounded-lg border border-sky-100 bg-sky-50 px-4 py-3">
						<Row label="Total" value={pricing.total} strong />
					</div>
				</div>
			</Section>
		</div>
	);
	const preparationCard = (
		<Section title="Indicaciones de preparación" icon={ClipboardDocumentCheckIcon}>
			<IndicationsAccordion studies={studies} />
		</Section>
	);
	const visitGuideCards = (
		<section className="grid gap-4 lg:grid-cols-3">
			<GuideStep number="1" icon={ClipboardDocumentCheckIcon} title="Antes de ir">
				<ul className="space-y-2">
					<CheckLine>
						<strong>Revisa</strong> las indicaciones de preparación.
					</CheckLine>
					<CheckLine>
						<strong>Lleva</strong> identificación oficial.
					</CheckLine>
					<CheckLine>
						<strong>Ten a la mano</strong> tu folio.
					</CheckLine>
					{hasConsecutive && (
						<CheckLine>
							<strong>Ten a la mano</strong> tu consecutivo.
						</CheckLine>
					)}
				</ul>
			</GuideStep>
			<GuideStep number="2" icon={IdentificationIcon} title="En sucursal">
				<div className="grid gap-2">
					<OperationalDatum label="Folio" value={order.laboratory_order_folio || order.folio} />
					<OperationalDatum label="Consecutivo" value={order.consecutive} />
					<OperationalDatum label="Paciente" value={patient.full_name || "Paciente"} />
					<OperationalDatum label="Fecha de nacimiento" value={patient.formatted_birth_date} />
				</div>
			</GuideStep>
			<GuideStep number="3" icon={CheckCircleIcon} title="Al llegar">
				<ol className="space-y-2">
					<li className="flex gap-2">
						<span className="font-semibold text-zinc-950">1.</span>
						<span>Presenta tu identificación.</span>
					</li>
					<li className="flex gap-2">
						<span className="font-semibold text-zinc-950">2.</span>
						<span>Comparte tu folio{hasConsecutive ? " y consecutivo" : ""}.</span>
					</li>
					<li className="flex gap-2">
						<span className="font-semibold text-zinc-950">3.</span>
						<span>Indica que vienes por estudios de laboratorio.</span>
					</li>
				</ol>
			</GuideStep>
		</section>
	);
	const noAppointmentStoresCard = !requiresAppointment ? (
		<NoAppointmentStores brand={brand} featuredStores={featuredStores} storeDirectoryUrl={storeDirectoryUrl} />
	) : null;
	const currentUrl = props?.ziggy?.location || (typeof window !== "undefined" ? window.location.href : "");
	const metaTitle = "Orden de compra de laboratorio | FAMEDIC";
	const metaDescription = "Consulta la información de tu orden de laboratorio, estudios solicitados, cita e indicaciones de preparación.";
	const metaImageUrl = buildAbsoluteUrl("/images/og/famedic-og.png", currentUrl);

	return (
		<>
			<Head title={metaTitle}>
				<meta name="description" content={metaDescription} />
				<meta property="og:type" content="website" />
				<meta property="og:site_name" content="FAMEDIC" />
				<meta property="og:title" content={metaTitle} />
				<meta property="og:description" content={metaDescription} />
				<meta property="og:image" content={metaImageUrl} />
				<meta property="og:image:alt" content="FAMEDIC, salud al alcance de todos" />
				<meta property="og:url" content={currentUrl} />
				<meta name="twitter:card" content="summary_large_image" />
				<meta name="twitter:title" content={metaTitle} />
				<meta name="twitter:description" content={metaDescription} />
				<meta name="twitter:image" content={metaImageUrl} />
			</Head>
			<main className="min-h-screen bg-zinc-50 text-zinc-950 dark:bg-slate-950">
				<div className="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
					<header className="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
						<div className="flex min-w-0 items-center justify-between gap-5 px-5 py-4">
							<div className="min-w-0">
								<div className="flex min-w-0 items-center gap-3">
									<ApplicationLogo className="h-10 w-auto shrink-0" />
									<span className="min-w-0 text-2xl font-bold uppercase text-[#171f45] dark:text-white">
										FAMEDIC
									</span>
								</div>
							</div>
							{brand.logo_url && (
								<div className="flex shrink-0 items-center justify-start lg:justify-end">
									<div className="rounded-lg border border-zinc-200 bg-white p-3">
										<img src={brand.logo_url} alt={brand.label || "Laboratorio"} className="h-12 w-auto" />
									</div>
								</div>
							)}
						</div>
						<div className="h-1 bg-lime-300" />
						<div className="bg-[#171f45] px-5 py-7 text-white">
							<div className="flex min-w-0 flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
								<div className="min-w-0">
									<p className="text-sm font-semibold uppercase text-lime-300">Orden de compra de laboratorio</p>
									<h1 className="mt-3 break-words text-3xl font-semibold tracking-normal text-white sm:text-4xl">
										{patient.full_name || "Paciente"}
									</h1>
									<div className="mt-5 flex min-w-0 flex-wrap items-center gap-2">
										{brand.label && (
											<span className="inline-flex min-h-8 items-center rounded-md bg-white/10 px-3 text-sm font-medium text-white ring-1 ring-white/15">
												{brand.label}
											</span>
										)}
										{order.folio && (
											<span className="inline-flex min-h-8 items-center rounded-md bg-white/10 px-3 text-sm font-medium text-white ring-1 ring-white/15">
												Folio {order.folio}
											</span>
										)}
										<StatusBadge appointment={appointment} />
									</div>
								</div>
								{pricing.total && (
									<div className="min-w-0 text-left lg:text-right">
										<p className="text-xs font-semibold uppercase text-white/70">Total</p>
										<p className="mt-2 break-words text-4xl font-bold tracking-normal text-lime-300 sm:text-5xl">
											{pricing.total}
										</p>
									</div>
								)}
							</div>
						</div>
					</header>

					{patientAndOrderCards}
					{appointmentCard}
					{studiesAndSummaryCards}
					{preparationCard}
					{visitGuideCards}
					{noAppointmentStoresCard}

					<footer className="border-t border-zinc-200 pt-5">
						<div className="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-4 text-sm text-zinc-700">
							<div className="flex min-w-0 flex-col items-center justify-center gap-4 text-center sm:flex-row sm:text-left">
								<p className="font-medium text-zinc-800">¿Necesitas ayuda? Tenemos estos canales de soporte.</p>
								<div className="flex w-full min-w-0 flex-col gap-2 sm:w-auto sm:flex-row">
									<a
										href="tel:8128601893"
										className="inline-flex min-h-11 items-center justify-center rounded-md border border-emerald-200 bg-white px-4 py-2 font-semibold text-[#171f45] transition hover:border-[#005e96] hover:text-[#005e96]"
									>
										<PhoneIcon className="mr-2 size-5 shrink-0 text-teal-700" aria-hidden />
										81 2860 1893
									</a>
									<a
										href={buildSupportWhatsAppUrl()}
										target="_blank"
										rel="noreferrer noopener"
										className="inline-flex min-h-11 items-center justify-center rounded-md border border-emerald-200 bg-white px-4 py-2 font-semibold text-[#171f45] transition hover:border-[#25D366] hover:text-[#128C4A]"
										aria-label="Contactar por WhatsApp"
									>
										<WhatsAppIcon className="mr-2 size-5 shrink-0 text-[#25D366]" />
										WhatsApp
									</a>
								</div>
							</div>
						</div>
					</footer>
				</div>
			</main>
		</>
	);
}
