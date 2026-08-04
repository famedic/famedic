import clsx from "clsx";
import { Badge } from "@/Components/Catalyst/badge";
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

const TONE = {
	sky: "border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950/50 dark:text-sky-200",
	blue: "border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-200",
	emerald:
		"border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200",
	purple:
		"border-violet-300 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950/50 dark:text-violet-200",
	amber: "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200",
	orange:
		"border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950/50 dark:text-orange-200",
	red: "border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-200",
	zinc: "border-zinc-300 bg-zinc-50 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-300",
};

const TRUTH_BADGE = {
	disponible: { label: "Disponible", color: "emerald" },
	proximamente: { label: "Próximamente", color: "zinc" },
	instrumentacion: { label: "Requiere instrumentación", color: "violet" },
};

export default function JourneyNode({
	node,
	selected = false,
	onSelect,
}) {
	const Icon = ICONS[node.icon] || ClockIcon;
	const tone = TONE[node.color] || TONE.zinc;
	const truth = TRUTH_BADGE[node.truth] || TRUTH_BADGE.proximamente;
	const planned = Boolean(node.planned);

	return (
		<button
			type="button"
			onClick={() => onSelect?.(node)}
			style={{ left: node.x, top: node.y }}
			className={clsx(
				"absolute w-36 rounded-2xl border-2 p-3 text-left shadow-sm transition",
				tone,
				planned && "border-dashed opacity-80",
				selected &&
					"ring-2 ring-famedic-light ring-offset-2 ring-offset-white dark:ring-offset-zinc-950",
				"hover:-translate-y-0.5 hover:shadow-md",
			)}
		>
			<div className="flex items-start justify-between gap-2">
				<span className="flex size-8 items-center justify-center rounded-full bg-white/70 dark:bg-zinc-950/40">
					<Icon className="size-4" aria-hidden="true" />
				</span>
				<Badge color={truth.color} className="!text-[9px]">
					{truth.label}
				</Badge>
			</div>
			<p className="mt-2 line-clamp-2 text-xs font-semibold leading-snug">
				{node.label}
			</p>
			<p className="mt-1 text-[10px] opacity-80">
				{node.date}
				{node.time ? ` · ${node.time}` : ""}
			</p>
			<p className="mt-0.5 truncate text-[10px] opacity-70">{node.status}</p>
			<p className="mt-0.5 truncate text-[10px] opacity-60">{node.origin}</p>
		</button>
	);
}
