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

export default function UserNavigationDropdown({
	children,
	dropdownMenuProps,
	dropdownButtonProps,
}) {
	const { userNavigation, auth } = usePage().props;

	if (!auth.user) {
		return null;
	}

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
				{children || <Avatar src={auth.user.profile_photo_url} />}
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
