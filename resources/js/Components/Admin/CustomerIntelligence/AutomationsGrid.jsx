import {
	EnvelopeIcon,
	ChatBubbleLeftRightIcon,
	TicketIcon,
	TagIcon,
	BoltIcon,
	QueueListIcon,
	BellIcon,
	CalendarDaysIcon,
	MegaphoneIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";

const ICONS = {
	envelope: EnvelopeIcon,
	chat: ChatBubbleLeftRightIcon,
	ticket: TicketIcon,
	tag: TagIcon,
	bolt: BoltIcon,
	queue: QueueListIcon,
	bell: BellIcon,
	calendar: CalendarDaysIcon,
	megaphone: MegaphoneIcon,
};

export default function AutomationsGrid({ automations = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Automatizaciones
				</h2>
				<p className="text-xs text-zinc-500 dark:text-zinc-400">
					Acciones de reactivación (preparadas para conectar con ActiveCampaign
					y cupones).
				</p>
			</div>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{automations.map((item) => {
					const Icon = ICONS[item.icon] || BoltIcon;
					return (
						<div
							key={item.id}
							className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex items-start justify-between gap-3">
								<span className="flex size-9 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
									<Icon className="size-4" />
								</span>
								<Badge color={item.enabled ? "lime" : "zinc"}>
									{item.enabled ? "Activo" : "Próximamente"}
								</Badge>
							</div>
							<p className="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{item.label}
							</p>
							<p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
								{item.description}
							</p>
						</div>
					);
				})}
			</div>
		</section>
	);
}
