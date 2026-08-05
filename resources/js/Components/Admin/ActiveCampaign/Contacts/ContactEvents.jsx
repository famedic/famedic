import {
	BoltIcon,
	CursorArrowRaysIcon,
	EnvelopeIcon,
	EnvelopeOpenIcon,
	ListBulletIcon,
	SparklesIcon,
	TagIcon,
} from "@heroicons/react/16/solid";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";

const ICON_MAP = {
	mail: EnvelopeIcon,
	"mail-open": EnvelopeOpenIcon,
	"cursor-click": CursorArrowRaysIcon,
	tag: TagIcon,
	list: ListBulletIcon,
	bolt: BoltIcon,
	activity: SparklesIcon,
};

function ActivityIcon({ name }) {
	const Icon = ICON_MAP[name] || SparklesIcon;

	return (
		<span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
			<Icon className="size-4" />
		</span>
	);
}

/**
 * Actividades reales desde ActiveCampaignMirrorService (snapshot.activities).
 */
export default function ContactEvents({
	ready = false,
	loading = false,
	mirror = null,
}) {
	const status = mirror?.status;
	const activities = Array.isArray(mirror?.activities) ? mirror.activities : [];
	const errored = status === "error" || status === "missing";
	const truth = !ready || loading ? "proxy" : errored ? "proxy" : "disponible";

	return (
		<ContactDrawerSection
			title="Eventos"
			description="Actividades recientes del contacto en ActiveCampaign."
			truth={truth}
		>
			{!ready || loading ? (
				<div className="space-y-2" aria-busy="true">
					{Array.from({ length: 3 }).map((_, i) => (
						<div
							key={i}
							className="h-14 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
						/>
					))}
				</div>
			) : errored ? (
				<Text className="text-sm text-zinc-500">
					{mirror?.message ||
						"No fue posible obtener la información de ActiveCampaign."}
				</Text>
			) : activities.length === 0 ? (
				<div className="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/80 px-4 py-6 text-center dark:border-zinc-700 dark:bg-zinc-950/40">
					<p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
						Sin actividades recientes
					</p>
					<Text className="mt-1 text-xs text-zinc-500">
						Las interacciones del contacto en ActiveCampaign se listarán aquí.
					</Text>
				</div>
			) : (
				<ul className="space-y-2">
					{activities.map((row, index) => (
						<li
							key={row.id || `${row.tstamp}-${index}`}
							className="flex gap-3 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-950/40"
						>
							<ActivityIcon name={row.icon} />
							<div className="min-w-0 flex-1 space-y-0.5">
								<div className="flex flex-wrap items-baseline justify-between gap-2">
									<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
										{row.type || "Actividad"}
									</p>
									<span className="text-[11px] text-zinc-400">
										{row.date || "—"}
									</span>
								</div>
								<Text className="text-xs text-zinc-500 dark:text-zinc-400">
									{row.description || row.type || "—"}
								</Text>
							</div>
						</li>
					))}
				</ul>
			)}
		</ContactDrawerSection>
	);
}
