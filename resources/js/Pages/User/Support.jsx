import SettingsLayout from "@/Layouts/SettingsLayout";
import Card from "@/Components/Card";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Divider } from "@/Components/Catalyst/divider";
import ServiceAvailabilityStatus from "@/Components/Support/ServiceAvailabilityStatus";
import useServiceHoursAvailability from "@/Hooks/useServiceHoursAvailability";
import {
	EnvelopeIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";
import clsx from "clsx";

function WhatsAppIcon({ className }) {
	return (
		<svg
			className={className}
			viewBox="0 0 256 259"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<path
				d="m67.663 221.823 4.185 2.093c17.44 10.463 36.971 15.346 56.503 15.346 61.385 0 111.609-50.224 111.609-111.609 0-29.297-11.859-57.897-32.785-78.824-20.927-20.927-48.83-32.785-78.824-32.785-61.385 0-111.61 50.224-110.912 112.307 0 20.926 6.278 41.156 16.741 58.594l2.79 4.186-11.16 41.156 41.853-10.464Z"
				fill="currentColor"
			/>
		</svg>
	);
}

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
				d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0-1.68-1.68c-.93 0-1.68.75-1.68 1.68 0 .93.75 1.68 1.68 1.68 0 .93.75 1.68 1.68 1.68.93 0 1.68-.75 1.68-1.68a1.68 1.68 0 0 0-1.68-1.68Z"
			/>
		</svg>
	);
}

function HoursList({ lines, timezoneLabel }) {
	if (!lines?.length) {
		return null;
	}

	return (
		<div className="space-y-1">
			{timezoneLabel && (
				<Text className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
					{timezoneLabel}
				</Text>
			)}
			<ul className="space-y-0.5">
				{lines.map((line) => (
					<li
						key={line}
						className="text-sm text-zinc-600 dark:text-zinc-300"
					>
						{line}
					</li>
				))}
			</ul>
		</div>
	);
}

function ExternalLinkButton({ href, children, className, ...props }) {
	return (
		<Button
			href={href}
			target="_blank"
			rel="noopener noreferrer"
			className={clsx("w-full sm:w-auto", className)}
			{...props}
		>
			{children}
		</Button>
	);
}

export default function Support({
	customerService,
	alternativeChannel,
	email,
	supportHours,
	concierge,
	appointmentConfirmation,
	social,
}) {
	const supportAvailability = useServiceHoursAvailability(supportHours);
	const conciergeAvailability = useServiceHoursAvailability({
		timezone: concierge?.timezone,
		scheduleByDay: concierge?.scheduleByDay,
	});

	return (
		<SettingsLayout title="Soporte">
			<div className="space-y-8">
				<header className="space-y-3">
					<GradientHeading noDivider>Soporte</GradientHeading>
					<Text className="max-w-2xl text-base text-zinc-700 dark:text-zinc-300">
						Estamos para ayudarte.
					</Text>
				</header>

				<section aria-labelledby="support-channels-heading" className="space-y-4">
					<Subheading id="support-channels-heading">
						Canales de Soporte
					</Subheading>

					{customerService && (
						<Card className="space-y-4 p-5 sm:p-6">
							<div>
								<Strong className="text-lg">{customerService.title}</Strong>
								<Text className="mt-1">
									Atención directa con{" "}
									<Strong>{customerService.contactName}</Strong> por WhatsApp.
								</Text>
							</div>
							<Text className="text-sm">{customerService.whatsappDisplay}</Text>
							{customerService.whatsappUrl && (
								<ExternalLinkButton
									href={customerService.whatsappUrl}
									color="emerald"
									className="!bg-[#25D366] hover:!bg-[#20bd5a]"
								>
									<WhatsAppIcon className="size-5" />
									Contactar por WhatsApp
								</ExternalLinkButton>
							)}
						</Card>
					)}

					{alternativeChannel && (
						<Card className="space-y-4 p-5 sm:p-6">
							<div className="flex flex-wrap items-start gap-2">
								<Strong className="text-lg">{alternativeChannel.title}</Strong>
								<Badge color="sky">{alternativeChannel.badge}</Badge>
							</div>
							<Text className="text-sm">{alternativeChannel.description}</Text>
							<Text className="text-sm">{alternativeChannel.whatsappDisplay}</Text>
							{alternativeChannel.whatsappUrl && (
								<ExternalLinkButton
									href={alternativeChannel.whatsappUrl}
									outline
									className="border-[#25D366] text-[#128C7E] hover:bg-emerald-50 dark:border-emerald-600 dark:text-emerald-300"
								>
									<WhatsAppIcon className="size-5" />
									{alternativeChannel.buttonLabel}
								</ExternalLinkButton>
							)}
						</Card>
					)}

					{email && (
						<Card className="space-y-4 p-5 sm:p-6">
							<Strong className="text-lg">Correo electrónico</Strong>
							<Text className="text-sm">{email.address}</Text>
							{email.mailtoUrl && (
								<Button href={email.mailtoUrl} outline className="w-full sm:w-auto">
									<EnvelopeIcon className="size-5" aria-hidden="true" />
									Enviar correo
								</Button>
							)}
						</Card>
					)}
				</section>

				{supportHours?.lines?.length > 0 && (
					<section aria-labelledby="support-hours-heading" className="space-y-4">
						<Subheading id="support-hours-heading">
							Horario de atención
						</Subheading>
						<Card className="space-y-4 p-5 sm:p-6">
							<ServiceAvailabilityStatus
								isAvailable={supportAvailability.isAvailable}
								availableMessage={supportHours.availableMessage}
								afterHoursMessage={supportHours.afterHoursMessage}
							/>
							<HoursList
								lines={supportHours.lines}
								timezoneLabel={supportHours.timezoneLabel}
							/>
						</Card>
					</section>
				)}

				<Divider soft />

				<section aria-labelledby="concierge-heading" className="space-y-4">
					<Subheading id="concierge-heading">Concierge Famedic</Subheading>
					<Card className="space-y-4 p-5 sm:p-6">
						<ServiceAvailabilityStatus
							isAvailable={conciergeAvailability.isAvailable}
							availableMessage={concierge?.availableMessage}
							afterHoursMessage={concierge?.afterHoursMessage}
						/>

						{concierge?.description && (
							<Text>{concierge.description}</Text>
						)}
						{appointmentConfirmation?.companionText && (
							<Text>{appointmentConfirmation.companionText}</Text>
						)}

						<div className="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-slate-700 dark:bg-slate-800/60">
							<Strong>Cómo se confirman las citas</Strong>
							{appointmentConfirmation?.text && (
								<Text className="text-sm">{appointmentConfirmation.text}</Text>
							)}
							{concierge?.telUrl && concierge?.phoneDisplay && (
								<Button href={concierge.telUrl} className="w-full sm:w-auto">
									<PhoneIcon className="size-5" aria-hidden="true" />
									Llamar a Concierge ({concierge.phoneDisplay})
								</Button>
							)}
						</div>

						{concierge?.scheduleLines?.length > 0 && (
							<div className="space-y-2">
								<Strong>Horario de Concierge</Strong>
								<HoursList lines={concierge.scheduleLines} />
							</div>
						)}
					</Card>
				</section>

				{social?.profiles?.length > 0 && (
					<section aria-labelledby="social-heading" className="space-y-4">
						<Subheading id="social-heading">
							Síguenos en redes sociales
						</Subheading>
						<Card className="space-y-4 p-5 sm:p-6">
							{social.intro && <Text>{social.intro}</Text>}
							<ul className="grid gap-3 sm:grid-cols-3">
								{social.profiles.map((profile) => (
									<li key={profile.network}>
										<Anchor
											href={profile.url}
											target="_blank"
											rel="noopener noreferrer"
											className={clsx(
												"flex min-h-11 items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3",
												"text-zinc-900 no-underline transition",
												"hover:border-famedic-light hover:bg-zinc-50",
												"focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-dark focus-visible:ring-offset-2",
												"dark:border-slate-700 dark:text-white dark:hover:bg-slate-800",
											)}
										>
											<SocialIcon
												icon={profile.icon}
												className="size-5 shrink-0 text-famedic-dark dark:text-famedic-lime"
											/>
											<span className="font-medium">{profile.network}</span>
										</Anchor>
									</li>
								))}
							</ul>
						</Card>
					</section>
				)}
			</div>
		</SettingsLayout>
	);
}
