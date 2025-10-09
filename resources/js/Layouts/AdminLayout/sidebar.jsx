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
import {
	ArrowRightStartOnRectangleIcon,
	ChevronUpIcon,
	ChevronDownIcon,
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
} from "@heroicons/react/16/solid";
import { Strong } from "@/Components/Catalyst/text";
import ApplicationLogo from "@/Components/ApplicationLogo";

import { usePage } from "@inertiajs/react";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";

export default function SideBar() {
	const { auth, adminNavigation, adminUserNavigation } = usePage().props;
	const { user } = auth;

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
	};

	return (
		<Sidebar>
			<SidebarHeader>
				<SidebarSection>
					<SidebarItem href={route("home")}>
						<ApplicationLogo className="h-6 w-auto" />
						<SidebarLabel>
							<Strong className="!font-poppins">Famedic</Strong>
						</SidebarLabel>
					</SidebarItem>
				</SidebarSection>
			</SidebarHeader>
			<SidebarBody>
				<SidebarSection>
					{adminNavigation.map((navItem) => {
						if (navItem.items) {
							const IconComponent = iconMap[navItem.icon];
							const hasActiveChild = navItem.items.some(
								(item) => item.current,
							);

							return (
								<Disclosure
									key={navItem.label}
									defaultOpen={hasActiveChild}
								>
									{({ open }) => (
										<>
											<DisclosureButton
												as={SidebarItem}
												current={hasActiveChild}
											>
												{IconComponent && (
													<IconComponent />
												)}
												<SidebarLabel>
													{navItem.label}
												</SidebarLabel>
												<ChevronDownIcon
													className={`${open ? "-rotate-180" : ""} transform transition-transform`}
												/>
											</DisclosureButton>
											<DisclosurePanel className="relative ml-4 space-y-20 pl-4">
												<div className="absolute left-0 top-0 h-[calc(100%-1rem)] border-l border-zinc-200 dark:border-zinc-900"></div>
												{navItem.items.map(
													({
														label,
														url,
														current,
														icon,
													}) => {
														const ItemIconComponent =
															iconMap[icon];
														return (
															<SidebarItem
																key={label}
																href={url}
																forceHoverStyle={
																	current
																}
															>
																<SidebarLabel>
																	{label}
																</SidebarLabel>
															</SidebarItem>
														);
													},
												)}
											</DisclosurePanel>
										</>
									)}
								</Disclosure>
							);
						}

						const { label, url, current, icon } = navItem;
						const IconComponent = iconMap[icon];
						return (
							<SidebarItem
								href={url}
								key={label}
								current={current}
								forceHoverStyle={current}
							>
								{IconComponent && <IconComponent />}
								<SidebarLabel>{label}</SidebarLabel>
							</SidebarItem>
						);
					})}
				</SidebarSection>
			</SidebarBody>
			<SidebarFooter className="max-lg:hidden">
				<Dropdown>
					<DropdownButton as={SidebarItem} dusk="adminUserNavigation">
						<span className="flex min-w-0 items-center gap-3">
							<Avatar
								src={user.profile_photo_url}
								className="size-10"
								square
								alt=""
							/>
							<span className="min-w-0">
								<span className="block truncate text-sm/5 font-medium text-zinc-950 dark:text-white">
									{user.name}
								</span>
								<span className="block truncate text-xs/5 font-normal text-zinc-500 dark:text-zinc-400">
									{user.email}
								</span>
							</span>
						</span>
						<ChevronUpIcon />
					</DropdownButton>
					<DropdownMenu className="min-w-64" anchor="top start">
						{adminUserNavigation.map(
							({ label, url, current, icon }) => {
								const IconComponent = iconMap[icon]; // Get the icon component from the map
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
