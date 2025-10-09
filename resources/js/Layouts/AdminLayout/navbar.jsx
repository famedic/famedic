export default function NavBar() {
	const { auth, adminUserNavigation } = usePage().props;
	const { user } = auth;

	// Mapping icon names to icon components
	const iconMap = {
		ArrowLeftEndOnRectangleIcon: ArrowLeftEndOnRectangleIcon,
	};

	return (
		<Navbar>
			<NavbarSpacer />
			<NavbarSection>
				<Link href={route("home")}>
					<ApplicationLogo className="h-6 w-auto" />
				</Link>
				<Dropdown>
					<DropdownButton as={NavbarItem} dusk="adminUserNavigation">
						<Avatar src={user.profile_photo_url} square />
					</DropdownButton>
					<DropdownMenu className="min-w-64" anchor="bottom end">
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
			</NavbarSection>
		</Navbar>
	);
}

import ApplicationLogo from "@/Components/ApplicationLogo";
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
	Navbar,
	NavbarItem,
	NavbarSection,
	NavbarSpacer,
} from "@/Components/Catalyst/navbar";
import {
	ArrowRightStartOnRectangleIcon,
	ArrowLeftEndOnRectangleIcon,
} from "@heroicons/react/16/solid";
import { Link, usePage } from "@inertiajs/react";
