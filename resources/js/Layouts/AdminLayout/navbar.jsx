import ApplicationLogo from "@/Components/ApplicationLogo";
import EnvironmentIndicator from "@/Components/EnvironmentBanner";
import { Avatar } from "@/Components/Catalyst/avatar";
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
import {
	Navbar,
	NavbarDivider,
	NavbarItem,
	NavbarLabel,
	NavbarSection,
	NavbarSpacer,
} from "@/Components/Catalyst/navbar";
import {
	ArrowLeftEndOnRectangleIcon,
	ArrowRightStartOnRectangleIcon,
	BanknotesIcon,
	BeakerIcon,
	BellIcon,
	BoltIcon,
	BookOpenIcon,
	ChartBarSquareIcon,
	ChevronDownIcon,
	ClipboardDocumentListIcon,
	Cog6ToothIcon,
	CpuChipIcon,
	IdentificationIcon,
	SignalIcon,
	Squares2X2Icon,
	UserCircleIcon,
	UsersIcon,
} from "@heroicons/react/16/solid";
import { Link, usePage } from "@inertiajs/react";

const iconMap = {
	ArrowLeftEndOnRectangleIcon,
	BanknotesIcon,
	BeakerIcon,
	BoltIcon,
	BookOpenIcon,
	ChartBarSquareIcon,
	ClipboardDocumentListIcon,
	Cog6ToothIcon,
	CpuChipIcon,
	IdentificationIcon,
	SignalIcon,
	Squares2X2Icon,
	UserCircleIcon,
	UsersIcon,
};

function itemIsCurrent(item) {
	if (!item) {
		return false;
	}

	return Boolean(item.current) || (item.items || []).some((child) => itemIsCurrent(child));
}

function TopDropdownItem({ item, showIcon = true }) {
	const Icon = showIcon && item.icon ? iconMap[item.icon] : null;

	return (
		<DropdownItem href={item.url}>
			{Icon ? <Icon data-slot="icon" /> : null}
			<DropdownLabel>{item.label}</DropdownLabel>
			{item.badge ? (
				<span className="rounded-md bg-lime-100 px-1.5 py-0.5 text-[10px] font-semibold text-lime-800">
					{item.badge}
				</span>
			) : null}
		</DropdownItem>
	);
}

function TopDropdown({
	label,
	icon: Icon,
	items = [],
	current = false,
	iconOnly = false,
	showItemIcons = true,
}) {
	if (!items.length) {
		return null;
	}

	return (
		<Dropdown>
			<DropdownButton
				as={NavbarItem}
				current={current}
				title={label}
				aria-label={label}
				className={iconOnly ? "gap-1.5" : undefined}
			>
				{Icon && <Icon data-slot="icon" />}
				{!iconOnly ? (
					<>
						<NavbarLabel className="hidden xl:inline">{label}</NavbarLabel>
						<ChevronDownIcon data-slot="icon" />
					</>
				) : null}
			</DropdownButton>
			<DropdownMenu className="min-w-56" anchor="bottom start">
				{items.map((item) => {
					if (item.items?.length) {
						return (
							<DropdownSection key={item.label}>
								<DropdownHeading>{item.label}</DropdownHeading>
								{item.items.map((child) => (
									<TopDropdownItem
										key={child.label}
										item={child}
										showIcon={showItemIcons}
									/>
								))}
							</DropdownSection>
						);
					}

					return (
						<TopDropdownItem
							key={item.label}
							item={item}
							showIcon={showItemIcons}
						/>
					);
				})}
			</DropdownMenu>
		</Dropdown>
	);
}

export default function NavBar() {
	const {
		auth,
		adminTopNavigation = {},
		adminUserNavigation = [],
	} = usePage().props;
	const { user } = auth;
	const integrations = adminTopNavigation.integrations || [];
	const monitoring = adminTopNavigation.monitoring || [];
	const personal = adminTopNavigation.personal;
	const configuration = adminTopNavigation.configuration || [];
	const adminMemberships = adminTopNavigation.memberships;
	const notifications = adminTopNavigation.notifications || [];

	return (
		<Navbar className="min-w-0">
			<NavbarSection className="min-w-0">
				<div className="flex min-w-0 items-center gap-2">
					<EnvironmentIndicator />
					<Link href={route("home")} className="shrink-0">
						<ApplicationLogo className="h-6 w-auto" />
					</Link>
				</div>
			</NavbarSection>

			<NavbarSpacer />

			<NavbarSection className="min-w-0 gap-1">
				<TopDropdown
					label="Integraciones"
					icon={Squares2X2Icon}
					items={integrations}
					current={integrations.some((item) => itemIsCurrent(item))}
					iconOnly
				/>
				<TopDropdown
					label="Monitoreo"
					icon={ChartBarSquareIcon}
					items={monitoring}
					current={monitoring.some((item) => itemIsCurrent(item))}
					iconOnly
				/>
				<TopDropdown
					label="Personal"
					icon={UsersIcon}
					items={personal?.items || []}
					current={itemIsCurrent(personal)}
					iconOnly
				/>
				<TopDropdown
					label="Admin Membresías"
					icon={IdentificationIcon}
					items={adminMemberships?.items || []}
					current={itemIsCurrent(adminMemberships)}
					iconOnly
				/>
				<TopDropdown
					label="Configuración"
					icon={Cog6ToothIcon}
					items={configuration}
					current={configuration.some((item) => itemIsCurrent(item))}
					iconOnly
					showItemIcons={false}
				/>
				<TopDropdown
					label="Notificaciones"
					icon={BellIcon}
					items={notifications}
					current={notifications.some((item) => itemIsCurrent(item))}
					iconOnly
				/>
			</NavbarSection>

			<NavbarDivider className="hidden lg:block" />

			<NavbarSection>
				<Dropdown>
					<DropdownButton
						as={NavbarItem}
						dusk="adminUserNavigation"
						title="Cuenta"
						aria-label="Cuenta"
						className="gap-2"
					>
						<Avatar src={user.profile_photo_url} square />
						<NavbarLabel className="hidden xl:inline">Cuenta</NavbarLabel>
						<ChevronDownIcon data-slot="icon" />
					</DropdownButton>
					<DropdownMenu className="min-w-64" anchor="bottom end">
						<div className="px-3 py-2">
							<div className="truncate text-sm font-semibold text-zinc-950 dark:text-white">
								{user.name}
							</div>
							<div className="truncate text-xs text-zinc-500 dark:text-zinc-400">
								{user.email}
							</div>
						</div>
						<DropdownDivider />
						<DropdownItem href={route("user.edit")}>
							<UserCircleIcon data-slot="icon" />
							<DropdownLabel>Ver mi cuenta</DropdownLabel>
						</DropdownItem>
						{adminUserNavigation.map(({ label, url, icon }) => {
							const Icon = iconMap[icon];

							return (
								<DropdownItem href={url} key={label}>
									{Icon ? <Icon data-slot="icon" /> : null}
									<DropdownLabel>{label}</DropdownLabel>
								</DropdownItem>
							);
						})}
						<DropdownDivider />
						<DropdownItem
							dusk="logout"
							href={route("logout")}
							method="post"
							as="button"
						>
							<ArrowRightStartOnRectangleIcon data-slot="icon" />
							<DropdownLabel>Cerrar sesión</DropdownLabel>
						</DropdownItem>
					</DropdownMenu>
				</Dropdown>
			</NavbarSection>
		</Navbar>
	);
}
