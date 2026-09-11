import SettingsLayout from "@/Layouts/SettingsLayout";
import Card from "@/Components/Card";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import CompactAvailabilityBadge from "@/Components/Support/CompactAvailabilityBadge";
import CompactHoursList from "@/Components/Support/CompactHoursList";
import AppointmentConfirmationSteps from "@/Components/Support/AppointmentConfirmationSteps";
import { WhatsAppIcon } from "@/Components/Checkout/CheckoutWhatsAppHelp";
import useServiceHoursAvailability from "@/Hooks/useServiceHoursAvailability";
import { usePage } from "@inertiajs/react";
import {
	EnvelopeIcon,
	PhoneIcon,
	ChatBubbleLeftRightIcon,
	ClockIcon,
	CalendarDaysIcon,
	CheckCircleIcon,
} from "@heroicons/react/24/outline";
import clsx from "clsx";

function SocialIcon({ icon, className }) {
	if (icon === "instagram") {
		return (
			<svg className={className} viewBox="0 0 24 24" aria-hidden="true">
				<path
					fill="currentColor"
					d="M7.8 2h8.4C19.4 2 22 4.6 22 7.8v8.4a5.8 5.8 0 0 1-5.8 5.8H7.8C4.6 22 2 19.4 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2m-.2 2A3.6 3.6 0 0 0 4 7.6v8.8A3.6 3.6 0 0 0 7.6 20h8.8a3.6 3.6 0 0 0 3.6-3.6V7.6A3.6 3.6 0 0 0 16.4 4H7.6m9.65 1.5a1.25 1.25 0 0 1 1.25 1.25A1.25 1.25 0 0 1 17.25 8 1.25 1.25 0 0 1 16 6.75a1.25 1.25 0 0 1 1.25-1.25M12 7a5 5 0 0 1 5 5 5 5 0 0 1-5 5 5 5 0 0 1-5-5 5 5 0 0 1 5-5m0 2a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3Z"
				/>
			</svg>
		);
	}

	if (icon === "facebook") {
		return (
			<svg className={className} viewBox="0 0 24 24" aria-hidden="true">
				<path
					fill="currentColor"
					d="M22 12a10 10 0 1 0-11.5 9.9v-7h-2v-3h2v-2.3c0-2 1.2-3.1 3-3.1.9 0 1.8.1 1.8.1v2h-1c-1 0-1.3.6-1.3 1.2V12h2.2l-.4 3h-1.8v7A10 10 0 0 0 22 12Z"
				/>
			</svg>
		);
	}

	return (
		<svg className={className} viewBox="0 0 24 24" aria-hidden="true">
			<path
				fill="currentColor"
				d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5-3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0-1.68-1.68c-.93 0-1.68.75-1.68 1.68 0 .93.75 1.68 1.68 1.68 0 .93.75 1.68 1.68 1.68.93 0 1.68-.75 1.68-1.68a1.68 1.68 0 0 0-1.68-1.68Z"
			/>
		</svg>
	);
}

function ContactChannelCard({
	icon,
	iconClassName,
	title,
	badge,
	description,
	contactLine,
	actionHref,
	actionLabel,
	actionOutline = true,
	actionIcon,
	ariaLabel,
}) {
	const ActionIcon = actionIcon ?? WhatsAppIcon;

	return (
		<Card className="flex h-full flex-col rounded-2xl p-5 shadow-sm ring-zinc-950/5">
			<div className="flex flex-col gap-4">
				<span
					className={clsx(
						"flex size-11 items-center justify-center rounded-xl",
						iconClassName,
					)}
				>
					{icon}
				</span>

				<div className="space-y-1.5">
					<div className="flex flex-wrap items-center gap-2">
						<Strong className="text-sm font-semibold text-zinc-900 dark:text-white">
							{title}
						</Strong>
						{badge && (
							<Badge
								color="zinc"
								className="px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide"
							>
								{badge}
							</Badge>
						)}
					</div>
					{description && (
						<Text className="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
							{description}
						</Text>
					)}
					{contactLine && (
						<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
							{contactLine}
						</Text>
					)}
				</div>
			</div>

			{actionHref && actionLabel && (
				<div className="mt-auto pt-5">
					<Button
						href={actionHref}
						target={actionHref.startsWith("http") ? "_blank" : undefined}
						rel={
							actionHref.startsWith("http")
								? "noopener noreferrer"
								: undefined
						}
						outline={actionOutline}
						className="w-full justify-center"
						aria-label={ariaLabel}
					>
						<ActionIcon className="size-5" aria-hidden="true" />
						{actionLabel}
					</Button>
				</div>
			)}
		</Card>
	);
}

function AppointmentWhatsAppCard({
	appointmentWhatsApp,
	conciergeTelUrl,
	conciergePhoneDisplay,
	isConciergeAvailable,
}) {
	const whatsAppUrl = appointmentWhatsApp?.url;
	const whatsAppDisplay = appointmentWhatsApp?.display;

	return (
		<Card className="overflow-hidden rounded-2xl border border-teal-100/90 bg-gradient-to-br from-emerald-50/40 via-white to-white shadow-sm ring-teal-100/60 dark:border-teal-900/40 dark:from-teal-950/20 dark:via-slate-900 dark:to-slate-900 dark:ring-teal-900/30">
			<div className="flex flex-col gap-6 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between lg:gap-8 lg:p-7">
				<div className="flex min-w-0 flex-col gap-5 sm:flex-row sm:items-start">
					<span className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-teal-100/80 dark:bg-slate-800 dark:ring-teal-900/50">
						<WhatsAppIcon className="size-9 text-[#25D366]" />
					</span>

					<div className="min-w-0 space-y-2">
						<p className="text-[11px] font-bold uppercase tracking-[0.14em] text-teal-800 dark:text-teal-300">
							Citas de laboratorio
						</p>
						<Strong className="block text-xl font-semibold text-zinc-900 sm:text-2xl dark:text-white">
							Confirma tu cita por WhatsApp
						</Strong>
						<Text className="max-w-xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
							Nuestro equipo te ayuda a elegir fecha, horario y sucursal.
						</Text>

						{whatsAppDisplay && (
							<p className="flex items-center gap-2 text-base font-semibold text-zinc-900 dark:text-white">
								<PhoneIcon
									className="size-4 shrink-0 text-teal-700 dark:text-teal-400"
									aria-hidden="true"
								/>
								{whatsAppDisplay}
							</p>
						)}

						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Canal oficial de citas
						</Text>

						<p
							className={clsx(
								"inline-flex items-center gap-1.5 text-sm font-medium",
								isConciergeAvailable
									? "text-emerald-700 dark:text-emerald-300"
									: "text-zinc-600 dark:text-zinc-400",
							)}
							role="status"
						>
							{isConciergeAvailable ? (
								<>
									<CheckCircleIcon
										className="size-4 shrink-0"
										aria-hidden="true"
									/>
									Disponible ahora
								</>
							) : (
								"Puedes escribirnos ahora y te responderemos en el siguiente horario de atención."
							)}
						</p>
					</div>
				</div>

				<div className="flex w-full shrink-0 flex-col gap-2.5 lg:w-56">
					{whatsAppUrl && (
						<Button
							href={whatsAppUrl}
							target="_blank"
							rel="noopener noreferrer"
							className="w-full justify-center !bg-[#25D366] !text-white hover:!bg-[#20bd5a]"
							aria-label="Abrir WhatsApp de citas de laboratorio"
						>
							<WhatsAppIcon className="size-5" aria-hidden="true" />
							Abrir WhatsApp
						</Button>
					)}
					{conciergeTelUrl && conciergePhoneDisplay && (
						<Button
							href={conciergeTelUrl}
							outline
							className="w-full justify-center"
							aria-label={`Llamar al ${conciergePhoneDisplay}`}
						>
							<PhoneIcon className="size-5" aria-hidden="true" />
							Llamar al {conciergePhoneDisplay}
						</Button>
					)}
				</div>
			</div>
		</Card>
	);
}

function HoursCard({ title, icon: Icon, isAvailable, lines, compactGeneral = false }) {
	return (
		<Card className="flex h-full flex-col space-y-4 rounded-2xl p-5 shadow-sm sm:p-6">
			<div className="flex items-start justify-between gap-3">
				<div className="flex items-center gap-2.5">
					<span className="flex size-9 items-center justify-center rounded-lg bg-sky-50 text-sky-800 ring-1 ring-sky-100 dark:bg-sky-950/40 dark:text-sky-200 dark:ring-sky-900/50">
						<Icon className="size-5" aria-hidden="true" />
					</span>
					<Strong className="text-sm font-semibold text-zinc-900 dark:text-white">
						{title}
					</Strong>
				</div>
				<CompactAvailabilityBadge isAvailable={isAvailable} />
			</div>
			<CompactHoursList lines={lines} compactGeneral={compactGeneral} />
		</Card>
	);
}

function SupportHelpBar() {
	return (
		<div className="rounded-2xl border border-emerald-100/80 bg-emerald-50/60 px-4 py-3.5 sm:px-5 dark:border-emerald-900/40 dark:bg-emerald-950/20">
			<p className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-zinc-700 dark:text-zinc-300">
				<PhoneIcon
					className="size-5 shrink-0 text-teal-700 dark:text-teal-400"
					aria-hidden="true"
				/>
				<span>¿Necesitas ayuda? Puedes contactarnos al</span>
				<Anchor
					href="tel:8128601893"
					className="font-semibold text-teal-700 no-underline hover:underline dark:text-teal-300"
				>
					81 2860 1893
				</Anchor>
			</p>
		</div>
	);
}

export default function Support({
	customerService,
	alternativeChannel,
	email,
	supportHours,
	concierge,
	social,
}) {
	const { famedicConcierge } = usePage().props;
	const appointmentWhatsApp = famedicConcierge?.appointmentWhatsApp;

	const supportAvailability = useServiceHoursAvailability(supportHours);
	const conciergeAvailability = useServiceHoursAvailability({
		timezone: concierge?.timezone ?? famedicConcierge?.timezone,
		scheduleByDay:
			concierge?.scheduleByDay ?? famedicConcierge?.scheduleByDay,
	});

	const conciergeTelUrl = concierge?.telUrl;
	const conciergePhoneDisplay =
		concierge?.phoneDisplay ?? famedicConcierge?.phoneDisplay;
	const conciergeScheduleLines =
		concierge?.scheduleLines ?? famedicConcierge?.scheduleLines ?? [];

	return (
		<SettingsLayout title="Soporte" hideHelpBubble>
			<div className="space-y-6 lg:space-y-8">
				<header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
					<div className="max-w-2xl space-y-2">
						<Heading
							level={1}
							className="!text-3xl !font-semibold !text-zinc-900 sm:!text-4xl dark:!text-white"
						>
							Soporte
						</Heading>
						<Text className="text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-400">
							Estamos aquí para ayudarte.
							<span className="mt-1 block">
								Elige el canal que mejor se adapte a ti.
							</span>
						</Text>
					</div>

					<p
						className={clsx(
							"inline-flex items-center gap-2 self-start rounded-full px-3 py-1.5 text-xs font-medium ring-1 sm:text-sm",
							conciergeAvailability.isAvailable
								? "bg-emerald-50 text-emerald-800 ring-emerald-200/80 dark:bg-emerald-950/30 dark:text-emerald-200 dark:ring-emerald-800/40"
								: "bg-zinc-100 text-zinc-600 ring-zinc-200/80 dark:bg-zinc-800/60 dark:text-zinc-400 dark:ring-zinc-700/50",
						)}
						role="status"
					>
						{conciergeAvailability.isAvailable ? (
							<>
								<span
									className="size-2 rounded-full bg-emerald-500 motion-safe:animate-pulse"
									aria-hidden="true"
								/>
								Equipo de citas disponible ahora
							</>
						) : (
							"Equipo de citas fuera de horario"
						)}
					</p>
				</header>

				{appointmentWhatsApp && (
					<AppointmentWhatsAppCard
						appointmentWhatsApp={appointmentWhatsApp}
						conciergeTelUrl={conciergeTelUrl}
						conciergePhoneDisplay={conciergePhoneDisplay}
						isConciergeAvailable={conciergeAvailability.isAvailable}
					/>
				)}

				<section aria-labelledby="other-channels-heading" className="space-y-4">
					<Subheading
						id="other-channels-heading"
						className="!text-base !font-semibold !text-zinc-900 dark:!text-white"
					>
						Otros canales de soporte
					</Subheading>

					<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
						{customerService && (
							<ContactChannelCard
								icon={
									<ChatBubbleLeftRightIcon
										className="size-5"
										aria-hidden="true"
									/>
								}
								iconClassName="bg-sky-50 text-sky-800 ring-1 ring-sky-100 dark:bg-sky-950/40 dark:text-sky-200 dark:ring-sky-900/50"
								title={customerService.title}
								description="Atención directa por WhatsApp."
								contactLine={customerService.whatsappDisplay}
								actionHref={customerService.whatsappUrl}
								actionLabel="Contactar por WhatsApp"
								ariaLabel="Contactar atención a clientes por WhatsApp"
							/>
						)}

						{alternativeChannel && (
							<ContactChannelCard
								icon={
									<WhatsAppIcon
										className="size-5 text-[#25D366]"
										aria-hidden="true"
									/>
								}
								iconClassName="bg-zinc-50 text-zinc-700 ring-1 ring-zinc-200/80 dark:bg-zinc-800/60 dark:text-zinc-300 dark:ring-zinc-700/50"
								title="Canal alternativo"
								badge={alternativeChannel.badge}
								description={alternativeChannel.description}
								contactLine={alternativeChannel.whatsappDisplay}
								actionHref={alternativeChannel.whatsappUrl}
								actionLabel={alternativeChannel.buttonLabel}
								ariaLabel="Abrir canal alternativo de WhatsApp"
							/>
						)}

						{email && (
							<ContactChannelCard
								icon={
									<EnvelopeIcon className="size-5" aria-hidden="true" />
								}
								iconClassName="bg-violet-50 text-violet-800 ring-1 ring-violet-100 dark:bg-violet-950/30 dark:text-violet-200 dark:ring-violet-900/40"
								title="Correo electrónico"
								description="Escríbenos y te responderemos."
								contactLine={email.address}
								actionHref={email.mailtoUrl}
								actionLabel="Enviar correo"
								actionIcon={EnvelopeIcon}
								ariaLabel={`Enviar correo a ${email.address}`}
							/>
						)}
					</div>
				</section>

				{(supportHours?.lines?.length > 0 ||
					conciergeScheduleLines.length > 0) && (
					<section
						aria-labelledby="support-hours-heading"
						className="space-y-4"
					>
						<Subheading
							id="support-hours-heading"
							className="!text-base !font-semibold !text-zinc-900 dark:!text-white"
						>
							Horarios y disponibilidad
						</Subheading>

						<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
							{supportHours?.lines?.length > 0 && (
								<HoursCard
									title="Atención general"
									icon={ClockIcon}
									isAvailable={supportAvailability.isAvailable}
									lines={supportHours.lines}
									compactGeneral
								/>
							)}

							{conciergeScheduleLines.length > 0 && (
								<HoursCard
									title="Concierge de citas"
									icon={CalendarDaysIcon}
									isAvailable={conciergeAvailability.isAvailable}
									lines={conciergeScheduleLines}
								/>
							)}

							<AppointmentConfirmationSteps className="md:col-span-2 xl:col-span-1" />
						</div>
					</section>
				)}

				{social?.profiles?.length > 0 && (
					<section aria-labelledby="social-heading" className="space-y-3">
						<Subheading
							id="social-heading"
							className="!text-base !font-semibold !text-zinc-900 dark:!text-white"
						>
							También puedes encontrarnos en
						</Subheading>
						<ul className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
							{social.profiles.map((profile) => (
								<li key={profile.network}>
									<Anchor
										href={profile.url}
										target="_blank"
										rel="noopener noreferrer"
										className={clsx(
											"inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-full",
											"border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700",
											"transition hover:border-zinc-300 hover:bg-zinc-50",
											"focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2",
											"sm:w-auto dark:border-zinc-700 dark:bg-slate-900 dark:text-zinc-300 dark:hover:bg-slate-800",
										)}
										aria-label={`Visitar ${profile.network} de Famedic`}
									>
										<SocialIcon
											icon={profile.icon}
											className="size-4 shrink-0"
										/>
										{profile.network}
									</Anchor>
								</li>
							))}
						</ul>
					</section>
				)}

				<SupportHelpBar />
			</div>
		</SettingsLayout>
	);
}
