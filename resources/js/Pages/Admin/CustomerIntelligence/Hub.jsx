import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import SidebarBadge from "@/Components/Admin/Sidebar/SidebarBadge";
import {
	ChartBarIcon,
	UserGroupIcon,
	MapIcon,
	ArrowTrendingUpIcon,
	HeartIcon,
	MegaphoneIcon,
	ShareIcon,
	SparklesIcon,
	ArrowRightIcon,
} from "@heroicons/react/24/outline";

const ICON_MAP = {
	dashboard: ChartBarIcon,
	dormant: UserGroupIcon,
	journey: MapIcon,
	cohorts: ArrowTrendingUpIcon,
	health: HeartIcon,
	referrals: ShareIcon,
	marketing: MegaphoneIcon,
	ai: SparklesIcon,
};

const ACCENT_STYLES = {
	indigo: {
		icon: "bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300",
		ring: "hover:border-indigo-200 hover:shadow-indigo-500/5 dark:hover:border-indigo-500/30",
	},
	orange: {
		icon: "bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300",
		ring: "hover:border-orange-200 hover:shadow-orange-500/5 dark:hover:border-orange-500/30",
	},
	sky: {
		icon: "bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300",
		ring: "hover:border-sky-200 hover:shadow-sky-500/5 dark:hover:border-sky-500/30",
	},
	purple: {
		icon: "bg-purple-50 text-purple-600 dark:bg-purple-500/10 dark:text-purple-300",
		ring: "hover:border-purple-200 hover:shadow-purple-500/5 dark:hover:border-purple-500/30",
	},
	green: {
		icon: "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300",
		ring: "hover:border-emerald-200 hover:shadow-emerald-500/5 dark:hover:border-emerald-500/30",
	},
	slate: {
		icon: "bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400",
		ring: "hover:border-zinc-300 dark:hover:border-zinc-600",
	},
	violet: {
		icon: "bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300",
		ring: "hover:border-violet-200 hover:shadow-violet-500/5 dark:hover:border-violet-500/30",
	},
};

function formatCount(value) {
	if (value === null || value === undefined) {
		return "—";
	}
	return new Intl.NumberFormat("es-MX").format(value);
}

function ModuleCard({ module }) {
	const Icon = ICON_MAP[module.icon] || ChartBarIcon;
	const accent = ACCENT_STYLES[module.accent] || ACCENT_STYLES.slate;
	const isComingSoon = module.status === "coming_soon";
	const isHub = Boolean(module.is_hub);
	const canOpen = Boolean(module.href) && !isComingSoon && !isHub;

	const card = (
		<div
			className={[
				"group relative flex h-full flex-col rounded-2xl border border-zinc-200/80 bg-white p-6 transition-all duration-300 ease-out",
				"dark:border-zinc-800 dark:bg-zinc-900/60",
				isComingSoon
					? "opacity-85"
					: `hover:-translate-y-0.5 hover:shadow-lg ${accent.ring}`,
			].join(" ")}
		>
			<div className="flex items-start justify-between gap-3">
				<div
					className={`flex size-11 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-105 ${accent.icon}`}
				>
					<Icon className="size-5" aria-hidden="true" />
				</div>
				{isComingSoon ? (
					<SidebarBadge variant="comingSoon" className="ml-0" />
				) : isHub ? (
					<SidebarBadge variant="beta" className="ml-0">
						Hub
					</SidebarBadge>
				) : null}
			</div>

			<div className="mt-5 flex-1">
				{isComingSoon ? (
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
						Próximamente
					</p>
				) : null}
				<h2 className="mt-1 text-lg font-semibold tracking-tight text-zinc-950 dark:text-white">
					{module.title}
				</h2>
				<p className="mt-1.5 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
					{module.description}
				</p>
			</div>

			<div className="mt-6 flex items-end justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
				<div>
					<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">
						{module.count_label || "Estado"}
					</p>
					<p className="mt-0.5 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
						{isComingSoon ? "Soon" : formatCount(module.count)}
					</p>
				</div>

				{canOpen ? (
					<span className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-950 px-3 py-1.5 text-xs font-semibold text-white transition group-hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:group-hover:bg-zinc-200">
						Abrir módulo
						<ArrowRightIcon className="size-3.5 transition-transform duration-300 group-hover:translate-x-0.5" />
					</span>
				) : isComingSoon ? (
					<span className="text-sm font-medium text-zinc-400">En roadmap</span>
				) : (
					<span className="text-sm font-medium text-indigo-600 dark:text-indigo-300">
						Portada activa
					</span>
				)}
			</div>
		</div>
	);

	if (!canOpen) {
		return <div className="h-full">{card}</div>;
	}

	return (
		<Link href={module.href} className="block h-full focus:outline-none">
			{card}
		</Link>
	);
}

export default function Hub({ modules = [], meta }) {
	return (
		<AdminLayout title={meta?.title || "Customer Intelligence"}>
			<div className="mx-auto max-w-7xl space-y-8">
				<header className="relative overflow-hidden rounded-3xl border border-zinc-200/70 bg-gradient-to-br from-zinc-50 via-white to-indigo-50/40 px-6 py-8 sm:px-8 dark:border-zinc-800 dark:from-zinc-950 dark:via-zinc-900 dark:to-indigo-950/30">
					<div className="flex flex-wrap items-start justify-between gap-4">
						<div className="max-w-2xl">
							<div className="flex flex-wrap items-center gap-2">
								<span className="text-2xl" aria-hidden="true">
									🧠
								</span>
								<SidebarBadge variant="beta" className="ml-0" />
							</div>
							<Heading className="mt-3 !text-3xl tracking-tight">
								{meta?.title || "Customer Intelligence Center"}
							</Heading>
							<Text className="mt-2 max-w-xl text-base text-zinc-500 dark:text-zinc-400">
								{meta?.subtitle ||
									"Inteligencia comercial para Marketing, Growth y Dirección."}
							</Text>
						</div>
						{meta?.generated_at ? (
							<div className="rounded-full border border-zinc-200 bg-white/80 px-3 py-1.5 text-xs text-zinc-500 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-400">
								Actualizado {meta.generated_at}
							</div>
						) : null}
					</div>
				</header>

				<section>
					<div className="mb-4 flex items-end justify-between gap-3">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
								Módulos
							</p>
							<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
								Herramientas de inteligencia
							</h2>
						</div>
						<Button plain href={route("admin.customers.dormant")} className="!text-sm">
							Ir a Clientes Dormidos
						</Button>
					</div>

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
						{modules.map((module) => (
							<ModuleCard key={module.id} module={module} />
						))}
					</div>
				</section>
			</div>
		</AdminLayout>
	);
}
