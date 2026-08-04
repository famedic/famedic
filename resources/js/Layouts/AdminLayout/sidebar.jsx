import { Avatar } from "@/Components/Catalyst/avatar";
import {
	Dropdown,
	DropdownButton,
	DropdownDivider,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import {
	Sidebar,
	SidebarBody,
	SidebarFooter,
	SidebarHeader,
	SidebarItem,
	SidebarLabel,
	SidebarSection,
} from "@/Components/Catalyst/sidebar";
import { Badge } from "@/Components/Catalyst/badge";
import {
	ArrowRightStartOnRectangleIcon,
	ChevronUpIcon,
	ChevronDownIcon,
	ChevronLeftIcon,
	ChevronRightIcon,
	UserGroupIcon,
	ShieldCheckIcon,
	ArrowLeftEndOnRectangleIcon,
	CalendarDaysIcon,
	BookOpenIcon,
	PresentationChartLineIcon,
	BeakerIcon,
	BuildingStorefrontIcon,
	UsersIcon,
	ClipboardDocumentListIcon,
	HeartIcon,
	BanknotesIcon,
	IdentificationIcon,
	SparklesIcon,
	MegaphoneIcon,
} from "@heroicons/react/16/solid";
import { Strong } from "@/Components/Catalyst/text";
import ApplicationLogo from "@/Components/ApplicationLogo";
import MarketingIntelligenceNav from "@/Components/Admin/ActiveCampaign/MarketingIntelligenceNav";
import {
	useAdminSidebarUi,
} from "@/Layouts/AdminLayout/SidebarUiContext";

import { usePage } from "@inertiajs/react";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { useEffect, useState } from "react";

const MI_PARENT_STORAGE_KEY = "mi-nav-parent-open-v1";

function NavItemBadge({ badge }) {
	if (!badge) {
		return null;
	}

	return (
		<Badge
			color="famedic-lime"
			className="ml-auto shrink-0 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
		>
			{badge}
		</Badge>
	);
}

function groupHasActiveChild(items = []) {
	return items.some((item) => {
		if (item.current) {
			return true;
		}
		if (Array.isArray(item.items)) {
			return groupHasActiveChild(item.items);
		}
		return false;
	});
}

function CollapsedFlyout({ label, icon: IconComponent, items, current }) {
	const [open, setOpen] = useState(false);

	return (
		<div
			className="relative"
			onMouseEnter={() => setOpen(true)}
			onMouseLeave={() => setOpen(false)}
		>
			<SidebarItem
				current={current}
				forceHoverStyle={current || open}
				title={label}
				aria-label={label}
				aria-expanded={open}
				className="justify-center"
				onClick={() => setOpen((value) => !value)}
			>
				{IconComponent ? <IconComponent data-slot="icon" /> : null}
			</SidebarItem>
			{open ? (
				<div
					role="region"
					aria-label={label}
					className="absolute left-full top-0 z-50 ml-2 max-h-[70vh] overflow-y-auto rounded-xl border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
				>
					<p className="mb-2 px-2 pt-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-zinc-500">
						{label}
					</p>
					<div className="min-w-[14rem] space-y-0.5">
						{items.map((item) => {
							if (item.items) {
								return (
									<div key={item.label} className="space-y-0.5">
										<p className="px-2 pt-2 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
											{item.label}
										</p>
										{item.items.map((child) => (
											<SidebarItem
												key={child.label}
												href={child.url}
												forceHoverStyle={child.current}
												current={child.current}
											>
												<SidebarLabel>{child.label}</SidebarLabel>
											</SidebarItem>
										))}
									</div>
								);
							}

							return (
								<SidebarItem
									key={item.label}
									href={item.url}
									forceHoverStyle={item.current}
									current={item.current}
								>
									<SidebarLabel>{item.label}</SidebarLabel>
									<NavItemBadge badge={item.badge} />
								</SidebarItem>
							);
						})}
					</div>
				</div>
			) : null}
		</div>
	);
}

export default function SideBar() {
	const { auth, adminNavigation, adminUserNavigation } = usePage().props;
	const { user } = auth;
	const { collapsed, rail, toggle } = useAdminSidebarUi();

	const iconMap = {
		UserGroupIcon: UserGroupIcon,
		UsersIcon: UsersIcon,
		ShieldCheckIcon: ShieldCheckIcon,
		ArrowLeftEndOnRectangleIcon: ArrowLeftEndOnRectangleIcon,
		CalendarDaysIcon: CalendarDaysIcon,
		BookOpenIcon: BookOpenIcon,
		PresentationChartLineIcon: PresentationChartLineIcon,
		BeakerIcon: BeakerIcon,
		BuildingStorefrontIcon: BuildingStorefrontIcon,
		ClipboardDocumentListIcon: ClipboardDocumentListIcon,
		HeartIcon: HeartIcon,
		BanknotesIcon: BanknotesIcon,
		IdentificationIcon: IdentificationIcon,
		SparklesIcon: SparklesIcon,
		MegaphoneIcon: MegaphoneIcon,
	};

	return (
		<Sidebar>
			<SidebarHeader>
				<SidebarSection>
					<SidebarItem href={route("home")} title="Famedic">
						<ApplicationLogo className="h-6 w-auto" />
						{!rail ? (
							<SidebarLabel>
								<Strong className="!font-poppins">Famedic</Strong>
							</SidebarLabel>
						) : null}
					</SidebarItem>
					<SidebarItem
						onClick={toggle}
						title={collapsed ? "Expandir menú" : "Colapsar menú"}
						aria-label={collapsed ? "Expandir menú" : "Colapsar menú"}
						className={rail ? "justify-center max-lg:hidden" : "max-lg:hidden"}
					>
						{rail ? (
							<ChevronRightIcon data-slot="icon" />
						) : (
							<>
								<ChevronLeftIcon data-slot="icon" />
								<SidebarLabel>Colapsar</SidebarLabel>
							</>
						)}
					</SidebarItem>
				</SidebarSection>
			</SidebarHeader>
			<SidebarBody>
				<SidebarSection>
					{adminNavigation.map((navItem) => {
						if (navItem.disabled) {
							const IconComponent = iconMap[navItem.icon];

							return (
								<SidebarItem
									key={navItem.label}
									disabled
									title="Temporalmente deshabilitado"
									className={rail ? "justify-center" : undefined}
								>
									{IconComponent && <IconComponent />}
									{!rail ? (
										<SidebarLabel>{navItem.label}</SidebarLabel>
									) : null}
								</SidebarItem>
							);
						}

						if (navItem.items) {
							const IconComponent = iconMap[navItem.icon];
							const hasActiveChild = groupHasActiveChild(navItem.items);
							const isMiGroups = navItem.layout === "mi-groups";

							if (rail) {
								if (isMiGroups) {
									return (
										<div key={navItem.label} className="space-y-1">
											<div
												className="mx-auto my-1 h-px w-6 bg-zinc-200 dark:bg-zinc-800"
												aria-hidden="true"
											/>
											<MarketingIntelligenceNav groups={navItem.items} />
										</div>
									);
								}

								return (
									<CollapsedFlyout
										key={navItem.label}
										label={navItem.label}
										icon={IconComponent}
										items={navItem.items}
										current={hasActiveChild}
									/>
								);
							}

							return (
								<MiAwareDisclosure
									key={navItem.label}
									navItem={navItem}
									IconComponent={IconComponent}
									hasActiveChild={hasActiveChild}
									isMiGroups={isMiGroups}
								/>
							);
						}

						const { label, url, current, icon, badge } = navItem;
						const IconComponent = iconMap[icon];
						return (
							<SidebarItem
								href={url}
								key={label}
								current={current}
								forceHoverStyle={current}
								title={label}
								className={rail ? "justify-center" : undefined}
							>
								{IconComponent && <IconComponent />}
								{!rail ? (
									<>
										<SidebarLabel>{label}</SidebarLabel>
										<NavItemBadge badge={badge} />
									</>
								) : null}
							</SidebarItem>
						);
					})}
				</SidebarSection>
			</SidebarBody>
			<SidebarFooter className="max-lg:hidden">
				<Dropdown>
					<DropdownButton
						as={SidebarItem}
						dusk="adminUserNavigation"
						title={user.name}
						className={rail ? "justify-center" : undefined}
					>
						<span className="flex min-w-0 items-center gap-3">
							<Avatar
								src={user.profile_photo_url}
								className="size-10"
								square
								alt=""
							/>
							{!rail ? (
								<span className="min-w-0">
									<span className="block truncate text-sm/5 font-medium text-zinc-950 dark:text-white">
										{user.name}
									</span>
									<span className="block truncate text-xs/5 font-normal text-zinc-500 dark:text-zinc-400">
										{user.email}
									</span>
								</span>
							) : null}
						</span>
						{!rail ? <ChevronUpIcon /> : null}
					</DropdownButton>
					<DropdownMenu className="min-w-64" anchor="top start">
						{adminUserNavigation.map(
							({ label, url, current, icon }) => {
								const IconComponent = iconMap[icon];
								return (
									<DropdownItem href={url} key={label}>
										{IconComponent && <IconComponent />}
										{label}
									</DropdownItem>
								);
							},
						)}
						<DropdownDivider />
						<DropdownItem
							dusk="logout"
							href={route("logout")}
							method="post"
							as="button"
						>
							<ArrowRightStartOnRectangleIcon />
							<DropdownLabel>Cerrar sesión</DropdownLabel>
						</DropdownItem>
					</DropdownMenu>
				</Dropdown>
			</SidebarFooter>
		</Sidebar>
	);
}

function MiAwareDisclosure({
	navItem,
	IconComponent,
	hasActiveChild,
	isMiGroups,
}) {
	const [open, setOpen] = useState(hasActiveChild);

	useEffect(() => {
		if (!isMiGroups) {
			return;
		}
		try {
			const stored = localStorage.getItem(MI_PARENT_STORAGE_KEY);
			if (stored === null) {
				setOpen(hasActiveChild);
				return;
			}
			setOpen(stored === "1" || hasActiveChild);
		} catch {
			setOpen(hasActiveChild);
		}
	}, [hasActiveChild, isMiGroups]);

	useEffect(() => {
		if (hasActiveChild) {
			setOpen(true);
		}
	}, [hasActiveChild]);

	const persistOpen = (next) => {
		setOpen(next);
		if (!isMiGroups) {
			return;
		}
		try {
			localStorage.setItem(MI_PARENT_STORAGE_KEY, next ? "1" : "0");
		} catch {
			// ignore
		}
	};

	if (!isMiGroups) {
		return (
			<Disclosure defaultOpen={hasActiveChild}>
				{({ open: panelOpen }) => (
					<>
						<DisclosureButton
							as={SidebarItem}
							current={hasActiveChild}
						>
							{IconComponent && <IconComponent />}
							<SidebarLabel>{navItem.label}</SidebarLabel>
							<ChevronDownIcon
								className={`${panelOpen ? "-rotate-180" : ""} transform transition-transform`}
							/>
						</DisclosureButton>
						<DisclosurePanel className="relative ml-4 space-y-0.5 pl-4">
							<div className="absolute left-0 top-0 h-[calc(100%-1rem)] border-l border-zinc-200 dark:border-zinc-900"></div>
							{navItem.items.map(({ label, url, current, badge }) => (
								<SidebarItem
									key={label}
									href={url}
									forceHoverStyle={current}
								>
									<SidebarLabel>{label}</SidebarLabel>
									<NavItemBadge badge={badge} />
								</SidebarItem>
							))}
						</DisclosurePanel>
					</>
				)}
			</Disclosure>
		);
	}

	return (
		<div>
			<SidebarItem
				current={hasActiveChild}
				forceHoverStyle={hasActiveChild}
				aria-expanded={open}
				onClick={() => persistOpen(!open)}
				title={navItem.label}
			>
				{IconComponent && <IconComponent />}
				<SidebarLabel>{navItem.label}</SidebarLabel>
				<ChevronDownIcon
					data-slot="icon"
					className={`${open ? "-rotate-180" : ""} transform transition-transform`}
				/>
			</SidebarItem>
			{open ? (
				<div className="mt-1 space-y-2 pl-1">
					<MarketingIntelligenceNav groups={navItem.items} />
				</div>
			) : null}
		</div>
	);
}
