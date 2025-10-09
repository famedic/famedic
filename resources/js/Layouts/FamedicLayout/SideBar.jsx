import {
	Sidebar,
	SidebarBody,
	SidebarHeader,
	SidebarItem,
	SidebarLabel,
	SidebarSection,
	SidebarFooter,
} from "@/Components/Catalyst/sidebar";
import { usePage } from "@inertiajs/react";
import { Avatar } from "@/Components/Catalyst/avatar";
import { ChevronDownIcon } from "@heroicons/react/16/solid";
import UserNavigationDropdown from "@/Components/UserNavigationDropdown";
import ApplicationLogo from "@/Components/ApplicationLogo";
import { Text, Strong } from "@/Components/Catalyst/text";
import { NavbarItem } from "@/Components/Catalyst/navbar";
import { ArrowRightEndOnRectangleIcon } from "@heroicons/react/24/solid";

export default function SideBar() {
	const { auth, mainNavigation } = usePage().props;
	const { user } = auth;

	return (
		<Sidebar>
			<SidebarHeader>
				{user ? (
					<UserNavigationDropdown
						dropdownButtonProps={{
							as: SidebarItem,
							dusk: "userNavigation",
						}}
						dropdownMenuProps={{
							className: "min-w-80 lg:min-w-64",
							anchor: "bottom start",
						}}
					>
						<Avatar src={user.profile_photo_url} />
						<SidebarLabel>{user.name}</SidebarLabel>
						<ChevronDownIcon />
					</UserNavigationDropdown>
				) : (
					<SidebarItem href={route("login")}>
						<SidebarLabel>Ingresar</SidebarLabel>
						<ArrowRightEndOnRectangleIcon />
					</SidebarItem>
				)}
			</SidebarHeader>
			<SidebarBody>
				<SidebarSection>
					{mainNavigation.map(({ label, url, current }) => (
						<SidebarItem current={current} key={label} href={url}>
							{label}
						</SidebarItem>
					))}
				</SidebarSection>
			</SidebarBody>
			<SidebarFooter>
				<NavbarItem href="/">
					<ApplicationLogo className="h-6 w-auto" />
					<Text>
						<Strong className="!font-poppins">Famedic</Strong>
					</Text>
				</NavbarItem>
			</SidebarFooter>
		</Sidebar>
	);
}
