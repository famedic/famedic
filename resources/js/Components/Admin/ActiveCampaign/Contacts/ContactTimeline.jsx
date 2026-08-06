import {
	UserPlusIcon,
	BeakerIcon,
	DocumentTextIcon,
	BuildingStorefrontIcon,
	ReceiptPercentIcon,
	HeartIcon,
	UserGroupIcon,
	TagIcon,
	BoltIcon,
	ClockIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";
import ContactTruthBadge from "./ContactTruthBadge";
import clsx from "clsx";

const ICONS = {
	user: UserPlusIcon,
	beaker: BeakerIcon,
	document: DocumentTextIcon,
	building: BuildingStorefrontIcon,
	receipt: ReceiptPercentIcon,
	heart: HeartIcon,
	users: UserGroupIcon,
	tag: TagIcon,
	bolt: BoltIcon,
};

const COLOR = {
	sky: "bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900",
	blue: "bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900",
	emerald:
		"bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900",
	purple:
		"bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950/40 dark:text-violet-300 dark:ring-violet-900",
	amber: "bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900",
	orange:
		"bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:ring-orange-900",
	red: "bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900",
	zinc: "bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700",
};

const BADGE_COLOR = {
	sky: "sky",
	blue: "blue",
	emerald: "emerald",
	purple: "violet",
	amber: "amber",
	orange: "orange",
	red: "red",
	zinc: "zinc",
};

function TimelineSkeleton() {
	return (
		<div className="space-y-4" aria-busy="true" aria-label="Cargando timeline">
			{Array.from({ length: 4 }).map((_, i) => (
				<div key={i} className="flex gap-3">
					<div className="size-9 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-800" />
					<div className="flex-1 space-y-2">
						<div className="h-3 w-1/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
						<div className="h-3 w-2/3 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800" />
					</div>
				</div>
			))}
		</div>
	);
}

function TimelineEvent({ event }) {
	const Icon = ICONS[event.icon] || ClockIcon;
	const tone = COLOR[event.color] || COLOR.zinc;
	const badgeColor = BADGE_COLOR[event.color] || "zinc";

	return (
		<li className="relative flex gap-3 pb-5 last:pb-0">
			<div className="absolute left-[1.125rem] top-9 bottom-0 w-px bg-zinc-200 last:hidden dark:bg-zinc-700" />
			<div
				className={clsx(
					"relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full ring-1",
					tone,
				)}
			>
				<Icon className="size-4" aria-hidden="true" />
			</div>
			<div className="min-w-0 flex-1 space-y-1.5 pt-0.5">
				<div className="flex flex-wrap items-center gap-2">
					<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						{event.type_label}
					</p>
					<Badge color={badgeColor}>{event.badge}</Badge>
					<Badge color="zinc">{event.status_label}</Badge>
				</div>
				<p className="text-sm text-zinc-600 dark:text-zinc-300">
					{event.description}
				</p>
				<div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-zinc-400">
					<span>
						{event.date} · {event.time}
					</span>
					<span>{event.source_label}</span>
				</div>
			</div>
		</li>
	);
}

/**
 * Timeline independiente del Drawer 360.
 * Consume el payload reutilizable preparado también para Customer Journey.
 */
export default function ContactTimeline({ timeline = null, loading = false }) {
	const events = timeline?.events || [];
	const upcoming = timeline?.upcoming || [];

	return (
		<ContactDrawerSection
			title="Timeline"
			description="Historial cronológico local de Famedic (sin API remota)."
			truth="disponible"
		>
			{loading || !timeline ? (
				<TimelineSkeleton />
			) : (
				<div className="space-y-5">
					{events.length === 0 ? (
						<Text className="text-sm text-zinc-500">
							Sin eventos registrados todavía para este paciente.
						</Text>
					) : (
						<ul className="space-y-0">
							{events.map((event) => (
								<TimelineEvent key={event.id} event={event} />
							))}
						</ul>
					)}

					{upcoming.length > 0 ? (
						<div className="rounded-lg border border-dashed border-zinc-200 p-3 dark:border-zinc-700">
							<div className="mb-2 flex items-center justify-between gap-2">
								<p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
									Tipos aún no instrumentados
								</p>
								<ContactTruthBadge truth="proximamente" />
							</div>
							<ul className="space-y-2">
								{upcoming.map((item) => (
									<li
										key={item.type}
										className="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400"
									>
										<span className="font-medium text-zinc-700 dark:text-zinc-300">
											{item.label}
										</span>
										<span>Próximamente · {item.reason}</span>
									</li>
								))}
							</ul>
						</div>
					) : null}
				</div>
			)}
		</ContactDrawerSection>
	);
}
