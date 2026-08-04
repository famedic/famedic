import { useCallback, useEffect, useMemo, useState } from "react";
import {
	ChevronDownIcon,
	ClipboardDocumentListIcon,
	PresentationChartLineIcon,
	ShieldCheckIcon,
	UsersIcon,
	BuildingStorefrontIcon,
} from "@heroicons/react/16/solid";
import {
	SidebarItem,
	SidebarLabel,
} from "@/Components/Catalyst/sidebar";
import { Badge } from "@/Components/Catalyst/badge";
import { useAdminSidebarUi } from "@/Layouts/AdminLayout/SidebarUiContext";
import clsx from "clsx";

const GROUPS_STORAGE_KEY = "mi-nav-groups-v1";

const GROUP_ICONS = {
	PresentationChartLineIcon,
	UsersIcon,
	BuildingStorefrontIcon,
	ClipboardDocumentListIcon,
	ShieldCheckIcon,
};

function readStoredGroups() {
	try {
		const raw = localStorage.getItem(GROUPS_STORAGE_KEY);
		if (!raw) {
			return {};
		}
		const parsed = JSON.parse(raw);
		return parsed && typeof parsed === "object" ? parsed : {};
	} catch {
		return {};
	}
}

function writeStoredGroups(state) {
	try {
		localStorage.setItem(GROUPS_STORAGE_KEY, JSON.stringify(state));
	} catch {
		// ignore
	}
}

function GroupCount({ count }) {
	return (
		<Badge
			color="zinc"
			className="ml-auto shrink-0 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums"
		>
			{count}
		</Badge>
	);
}

function ModuleLinks({ items, dense = false }) {
	return (
		<ul className={clsx("space-y-0.5", dense && "min-w-[12rem] p-1")}>
			{items.map((item) => (
				<li key={item.label}>
					<SidebarItem
						href={item.url}
						forceHoverStyle={item.current}
						current={item.current}
					>
						<SidebarLabel>{item.label}</SidebarLabel>
					</SidebarItem>
				</li>
			))}
		</ul>
	);
}

function RailGroup({ group, Icon }) {
	const [open, setOpen] = useState(false);

	return (
		<div
			className="relative"
			onMouseEnter={() => setOpen(true)}
			onMouseLeave={() => setOpen(false)}
		>
			<SidebarItem
				current={group.current}
				forceHoverStyle={group.current || open}
				title={group.label}
				aria-label={`${group.label} (${group.count} módulos)`}
				aria-expanded={open}
				className="justify-center"
				onClick={() => setOpen((value) => !value)}
			>
				{Icon ? <Icon data-slot="icon" /> : null}
			</SidebarItem>
			{open ? (
				<div
					role="region"
					aria-label={group.label}
					className="absolute left-full top-0 z-50 ml-2 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="mb-2 flex items-center justify-between gap-3 px-2 pt-1">
						<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-zinc-500">
							{group.label}
						</p>
						<GroupCount count={group.count} />
					</div>
					<ModuleLinks items={group.items || []} dense />
				</div>
			) : null}
		</div>
	);
}

function ExpandableGroup({ group, Icon, open, onToggle }) {
	return (
		<div
			className={clsx(
				"rounded-lg",
				group.current &&
					"ring-1 ring-inset ring-famedic-darker/15 dark:ring-famedic-light/20",
			)}
		>
			<SidebarItem
				current={group.current}
				forceHoverStyle={group.current}
				aria-expanded={open}
				aria-controls={`mi-nav-group-${group.key}`}
				title={group.label}
				onClick={() => onToggle(group.key, !open)}
			>
				{Icon ? <Icon data-slot="icon" /> : null}
				<SidebarLabel className="min-w-0 flex-1 truncate text-left">
					{group.label}
				</SidebarLabel>
				<GroupCount count={group.count} />
				<ChevronDownIcon
					data-slot="icon"
					className={clsx(
						"transition-transform",
						open && "-rotate-180",
					)}
				/>
			</SidebarItem>

			{open ? (
				<div
					id={`mi-nav-group-${group.key}`}
					className="relative ml-3 space-y-0.5 border-l border-zinc-200 pb-1 pl-3 dark:border-zinc-800"
					role="region"
					aria-label={group.label}
				>
					<ModuleLinks items={group.items || []} />
				</div>
			) : null}
		</div>
	);
}

/**
 * Navegación agrupada de Marketing Intelligence (solo UX).
 * No altera rutas ni pantallas: reorganiza el menú en 5 secciones colapsables.
 */
export default function MarketingIntelligenceNav({ groups = [] }) {
	const { rail } = useAdminSidebarUi();
	const [expandedMap, setExpandedMap] = useState(() => readStoredGroups());

	const activeGroupKey = useMemo(
		() => groups.find((group) => group.current)?.key ?? null,
		[groups],
	);

	useEffect(() => {
		if (!activeGroupKey) {
			return;
		}
		setExpandedMap((prev) => {
			if (prev[activeGroupKey] === true) {
				return prev;
			}
			const next = { ...prev, [activeGroupKey]: true };
			writeStoredGroups(next);
			return next;
		});
	}, [activeGroupKey]);

	const onToggle = useCallback((key, willOpen) => {
		setExpandedMap((prev) => {
			const next = { ...prev, [key]: willOpen };
			writeStoredGroups(next);
			return next;
		});
	}, []);

	if (!groups.length) {
		return null;
	}

	if (rail) {
		return (
			<nav
				aria-label="Marketing Intelligence — grupos"
				className="flex flex-col gap-1"
			>
				{groups.map((group) => {
					const Icon = GROUP_ICONS[group.icon] || null;
					return (
						<RailGroup key={group.key} group={group} Icon={Icon} />
					);
				})}
			</nav>
		);
	}

	return (
		<nav
			aria-label="Marketing Intelligence — secciones"
			className="flex flex-col gap-2"
		>
			{groups.map((group, index) => {
				const Icon = GROUP_ICONS[group.icon] || null;
				const open = expandedMap[group.key] === true;

				return (
					<div key={group.key} className="space-y-1">
						{index > 0 ? (
							<div
								aria-hidden="true"
								className="mx-2 border-t border-zinc-950/5 dark:border-white/10"
							/>
						) : null}
						<ExpandableGroup
							group={group}
							Icon={Icon}
							open={open}
							onToggle={onToggle}
						/>
					</div>
				);
			})}
		</nav>
	);
}
