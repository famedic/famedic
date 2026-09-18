import * as Headless from "@headlessui/react";
import clsx from "clsx";
import {
	Dropdown,
	DropdownButton,
	DropdownDivider,
	DropdownHeading,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
	DropdownSection,
} from "@/Components/Catalyst/dropdown";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Link } from "@/Components/Catalyst/link";
import {
	ArrowRightStartOnRectangleIcon,
	ShieldCheckIcon,
	ShoppingBagIcon,
	CreditCardIcon,
	MapPinIcon,
	UserCircleIcon,
	CommandLineIcon,
	UsersIcon,
	IdentificationIcon,
	BookOpenIcon,
	BuildingLibraryIcon,
	LifebuoyIcon,
	ChevronRightIcon,
} from "@heroicons/react/16/solid";
import { usePage } from "@inertiajs/react";

/** Iniciales para avatar cuando no hay foto o falla la carga */
export function getAvatarInitials(user) {
	if (!user) return "U";
	const full = (user.full_name || user.name || "").trim();
	if (full) {
		const parts = full.split(/\s+/).filter(Boolean);
		if (parts.length >= 2) {
			return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase().slice(0, 2);
		}
		return full.slice(0, 2).toUpperCase();
	}
	const local = user.email?.split("@")[0];
	if (local) return local.slice(0, 2).toUpperCase();
	return "U";
}

export function getAvatarDisplayName(user) {
	if (!user) return "Usuario";
	return (user.full_name || user.name || user.email?.split("@")[0] || "Usuario").trim() || "Usuario";
}

const ICON_MAP = {
	UserCircleIcon,
	MapPinIcon,
	CreditCardIcon,
	ShoppingBagIcon,
	CommandLineIcon,
	UsersIcon,
	IdentificationIcon,
	BuildingLibraryIcon,
	LifebuoyIcon,
};

const QUICK_ACTION_ORDER = ["Mis pedidos", "Compras pendientes"];

const QUICK_ACTION_META = {
	"Mis pedidos": { subtitle: "Ver historial" },
	"Compras pendientes": { subtitle: "Revisar ahora" },
};

const INFO_LABELS = new Set([
	"Mi cuenta",
	"Mis perfiles fiscales",
	"Mis métodos de pago",
	"Mis direcciones",
	"Mis pacientes frecuentes",
	"Mi familia",
]);

const TOOLS_LABELS = new Set(["Soporte", "Administración"]);

const SECTION_HEADING_CLASS =
	"!mx-0 !mb-0.5 !mt-2.5 first:!mt-1 !px-2 !pb-0 !pt-0 !text-[10px] !font-semibold !uppercase !tracking-[0.06em] !text-zinc-400 dark:!text-slate-500";

const MENU_ITEM_CLASS = clsx(
	"!rounded-md !px-2.5 !py-1.5 !text-sm/5 !transition-colors !duration-150",
	"[&_[data-slot=icon]]:!col-start-1 [&_[data-slot=icon]]:!row-start-1",
	"[&_[data-slot=icon]]:!mr-2.5 [&_[data-slot=icon]]:!size-4 [&_[data-slot=icon]]:!w-4 [&_[data-slot=icon]]:!min-w-4 [&_[data-slot=icon]]:!shrink-0",
	"[&_[data-slot=icon]]:!text-zinc-500 dark:[&_[data-slot=icon]]:!text-slate-400",
	"data-[focus]:!bg-famedic-dark/[0.07] data-[focus]:!text-famedic-dark",
	"data-[focus]:[&_[data-slot=icon]]:!text-famedic-dark",
	"dark:data-[focus]:!bg-famedic-dark/15 dark:data-[focus]:!text-famedic-300",
	"dark:data-[focus]:[&_[data-slot=icon]]:!text-famedic-300",
);

function partitionNavigation(items = []) {
	const quick = [];
	const info = [];
	const tools = [];

	for (const item of items) {
		if (QUICK_ACTION_ORDER.includes(item.label)) {
			quick.push(item);
		} else if (INFO_LABELS.has(item.label)) {
			info.push(item);
		} else if (TOOLS_LABELS.has(item.label)) {
			tools.push(item);
		}
	}

	quick.sort(
		(a, b) => QUICK_ACTION_ORDER.indexOf(a.label) - QUICK_ACTION_ORDER.indexOf(b.label),
	);

	return { quick, info, tools };
}

function activeMenuItemClass(current) {
	if (!current) return MENU_ITEM_CLASS;

	return clsx(
		MENU_ITEM_CLASS,
		"!bg-famedic-dark/[0.08] !text-famedic-dark dark:!bg-famedic-dark/20 dark:!text-famedic-300",
		"[&_[data-slot=icon]]:!text-famedic-dark dark:[&_[data-slot=icon]]:!text-famedic-300",
		"data-[focus]:!bg-famedic-dark/[0.12] data-[focus]:!text-famedic-dark",
		"dark:data-[focus]:!bg-famedic-dark/25 dark:data-[focus]:!text-famedic-300",
	);
}

function AccountMenuItem({ item }) {
	const IconComponent = ICON_MAP[item.icon];

	return (
		<DropdownItem href={item.url} className={activeMenuItemClass(item.current)}>
			{IconComponent && <IconComponent data-slot="icon" />}
			<DropdownLabel className="!truncate">{item.label}</DropdownLabel>
		</DropdownItem>
	);
}

function AccountQuickAction({ item, badgeCount = 0 }) {
	const IconComponent = ICON_MAP[item.icon] || ShoppingBagIcon;
	const subtitle = QUICK_ACTION_META[item.label]?.subtitle || "";
	const isActive = Boolean(item.current);

	return (
		<Headless.MenuItem className="col-span-1">
			{({ focus }) => (
				<Link
					href={item.url}
					className={clsx(
						"group flex flex-col rounded-lg border border-zinc-200/90 bg-white px-2.5 py-2 text-left transition-colors duration-150 dark:border-slate-700/80 dark:bg-slate-900/40",
						"hover:border-famedic-dark/25 hover:bg-famedic-dark/[0.03]",
						focus && "border-famedic-dark/30 bg-famedic-dark/[0.04] ring-1 ring-famedic-dark/15",
						isActive &&
							"border-famedic-dark/35 bg-famedic-dark/[0.06] dark:border-famedic-dark/40 dark:bg-famedic-dark/15",
					)}
				>
					<div className="mb-1.5 flex items-start justify-between gap-1.5">
						<span
							className={clsx(
								"inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100/90 text-zinc-600 transition-colors dark:bg-slate-800 dark:text-slate-300",
								(isActive || focus) && "bg-famedic-dark/10 text-famedic-dark dark:text-famedic-300",
							)}
						>
							<IconComponent className="size-3.5" aria-hidden="true" />
						</span>
						{badgeCount > 0 && (
							<span
								className="inline-flex min-h-4 min-w-4 items-center justify-center rounded-full bg-famedic-dark/10 px-1 text-[10px] font-semibold leading-none text-famedic-dark dark:bg-famedic-dark/25 dark:text-famedic-300"
								aria-label={`${badgeCount} compras pendientes`}
							>
								{badgeCount}
							</span>
						)}
					</div>

					<p
						className={clsx(
							"text-[13px] font-semibold leading-tight text-zinc-900 dark:text-white",
							isActive && "text-famedic-dark dark:text-famedic-300",
						)}
					>
						{item.label}
					</p>

					<div className="mt-0.5 flex items-center justify-between gap-1">
						<p className="text-[11px] leading-tight text-zinc-500 dark:text-slate-400">{subtitle}</p>
						<ChevronRightIcon
							className={clsx(
								"size-3 shrink-0 text-zinc-400 transition-colors dark:text-slate-500",
								"group-hover:text-famedic-dark group-data-[focus]:text-famedic-dark",
								isActive && "text-famedic-dark dark:text-famedic-300",
							)}
							aria-hidden="true"
						/>
					</div>
				</Link>
			)}
		</Headless.MenuItem>
	);
}

export default function UserNavigationDropdown({
	children,
	dropdownMenuProps,
	dropdownButtonProps,
}) {
	const { userNavigation, auth, pendingPurchasesSummary } = usePage().props;

	if (!auth.user) {
		return null;
	}

	const initials = getAvatarInitials(auth.user);
	const displayName = getAvatarDisplayName(auth.user);
	const photoUrl = auth.user.profile_photo_url || null;
	const email = auth.user.email || "";
	const pendingCount = Number(pendingPurchasesSummary?.total ?? 0);

	const { quick, info, tools } = partitionNavigation(userNavigation);
	const accountUrl = info.find((item) => item.label === "Mi cuenta")?.url || route("user.edit");

	const menuClassName = clsx(
		"!flex !w-[min(100vw-1rem,24.375rem)] !min-w-[22.5rem] !max-w-[calc(100vw-1rem)] !flex-col !gap-0 !p-1.5",
		dropdownMenuProps?.className,
	);

	const subtleDividerClass = "!mx-1.5 !my-1 !h-px !bg-zinc-950/[0.06] dark:!bg-white/10";

	return (
		<Dropdown>
			<DropdownButton {...dropdownButtonProps}>
				{children || (
					<Avatar
						src={photoUrl}
						initials={initials}
						alt={displayName}
						className="size-7 bg-zinc-200 text-zinc-700 dark:bg-slate-600 dark:text-white sm:size-6"
					/>
				)}
			</DropdownButton>
			<DropdownMenu {...dropdownMenuProps} className={menuClassName}>
				<Headless.MenuItem>
					{({ focus }) => (
						<Link
							href={accountUrl}
							className={clsx(
								"flex items-center gap-2.5 rounded-lg px-2 py-1.5 transition-colors duration-150",
								focus && "bg-famedic-dark/[0.06] dark:bg-famedic-dark/15",
							)}
						>
							<Avatar
								src={photoUrl}
								initials={initials}
								alt={displayName}
								className="size-8 shrink-0 bg-zinc-200 text-zinc-700 dark:bg-slate-700 dark:text-white"
							/>
							<div className="min-w-0 flex-1">
								<p className="truncate text-sm font-semibold leading-tight text-zinc-900 dark:text-white">
									{displayName}
								</p>
								{email && (
									<p className="truncate text-[11px] leading-tight text-zinc-500 dark:text-slate-400">
										{email}
									</p>
								)}
							</div>
							<ChevronRightIcon
								className="size-3.5 shrink-0 self-center text-zinc-400 dark:text-slate-500"
								aria-hidden="true"
							/>
						</Link>
					)}
				</Headless.MenuItem>

				<DropdownDivider className={subtleDividerClass} />

				{quick.length > 0 && (
					<div className="grid grid-cols-1 gap-1.5 px-0.5 py-1 sm:grid-cols-2">
						{quick.map((item) => (
							<AccountQuickAction
								key={item.label}
								item={item}
								badgeCount={item.label === "Compras pendientes" ? pendingCount : 0}
							/>
						))}
					</div>
				)}

				{info.length > 0 && (
					<DropdownSection className="!px-0">
						<DropdownHeading className={SECTION_HEADING_CLASS}>Mi información</DropdownHeading>
						{info.map((item) => (
							<AccountMenuItem key={item.label} item={item} />
						))}
					</DropdownSection>
				)}

				{tools.length > 0 && (
					<DropdownSection className="!px-0">
						<DropdownHeading className={SECTION_HEADING_CLASS}>Herramientas</DropdownHeading>
						{tools.map((item) => (
							<AccountMenuItem key={item.label} item={item} />
						))}
					</DropdownSection>
				)}

				<DropdownSection className="!px-0">
					<DropdownHeading className={SECTION_HEADING_CLASS}>Legal</DropdownHeading>
					<DropdownItem href={route("privacy-policy")} className={MENU_ITEM_CLASS}>
						<ShieldCheckIcon data-slot="icon" />
						<DropdownLabel className="!truncate">Política de privacidad</DropdownLabel>
					</DropdownItem>
					<DropdownItem href={route("terms-of-service")} className={MENU_ITEM_CLASS}>
						<BookOpenIcon data-slot="icon" />
						<DropdownLabel className="!truncate">Términos y condiciones de servicio</DropdownLabel>
					</DropdownItem>
				</DropdownSection>

				<DropdownDivider className={subtleDividerClass} />

				<DropdownItem
					dusk="logout"
					as="button"
					method="post"
					href="/logout"
					className={clsx(
						MENU_ITEM_CLASS,
						"!text-red-600 dark:!text-red-400",
						"data-[focus]:!bg-red-50 data-[focus]:!text-red-700",
						"dark:data-[focus]:!bg-red-950/35 dark:data-[focus]:!text-red-300",
						"[&_[data-slot=icon]]:!text-red-600 dark:[&_[data-slot=icon]]:!text-red-400",
						"data-[focus]:[&_[data-slot=icon]]:!text-red-700 dark:data-[focus]:[&_[data-slot=icon]]:!text-red-300",
					)}
				>
					<ArrowRightStartOnRectangleIcon data-slot="icon" />
					<DropdownLabel>Cerrar sesión</DropdownLabel>
				</DropdownItem>
			</DropdownMenu>
		</Dropdown>
	);
}
