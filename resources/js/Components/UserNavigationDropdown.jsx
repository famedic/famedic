import {
	Dropdown,
	DropdownButton,
	DropdownDivider,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { Avatar } from "@/Components/Catalyst/avatar";
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

export default function UserNavigationDropdown({
	children,
	dropdownMenuProps,
	dropdownButtonProps,
}) {
	const { userNavigation, auth } = usePage().props;

	if (!auth.user) {
		return null;
	}

	const initials = getAvatarInitials(auth.user);
	const displayName = getAvatarDisplayName(auth.user);
	const photoUrl = auth.user.profile_photo_url || null;

	const iconMap = {
		UserCircleIcon: UserCircleIcon,
		MapPinIcon: MapPinIcon,
		CreditCardIcon: CreditCardIcon,
		ShoppingBagIcon: ShoppingBagIcon,
		CommandLineIcon: CommandLineIcon,
		UsersIcon: UsersIcon,
		IdentificationIcon: IdentificationIcon,
		BuildingLibraryIcon: BuildingLibraryIcon,
	};

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
			<DropdownMenu {...dropdownMenuProps}>
				{userNavigation.map(({ label, url, icon }) => {
					const IconComponent = iconMap[icon];
					return (
						<DropdownItem href={url} key={label}>
							{IconComponent && <IconComponent />}
							<DropdownLabel>{label}</DropdownLabel>
						</DropdownItem>
					);
				})}
				<DropdownDivider />
				<DropdownItem href={route("privacy-policy")}>
					<ShieldCheckIcon />
					<DropdownLabel>Política de privacidad</DropdownLabel>
				</DropdownItem>
				<DropdownItem href={route("terms-of-service")}>
					<BookOpenIcon />
					<DropdownLabel>
						Términos y condiciones de servicio
					</DropdownLabel>
				</DropdownItem>
				<DropdownDivider />
				<DropdownItem
					dusk="logout"
					as="button"
					method="post"
					href="/logout"
				>
					<ArrowRightStartOnRectangleIcon />
					<DropdownLabel>Cerrar sesión</DropdownLabel>
				</DropdownItem>
			</DropdownMenu>
		</Dropdown>
	);
}
