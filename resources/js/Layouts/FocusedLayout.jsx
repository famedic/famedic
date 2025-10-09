export default function FocusedLayout({
	title,
	hideHelpBubble = false,
	children,
}) {
	const { auth, hasOdessaAfiliateAccount } = usePage().props;

	useTrackingEvents();

	return (
		<div className="min-h-screen bg-neutral-50 dark:bg-slate-950">
			<div className="mx-auto max-w-[100rem]">
				<Head title={title} />

				{/* Navbar */}
				<header className="px-4 pt-2">
					<Navbar className="flex items-center justify-between">
						<NavbarItem
							href={auth.user ? route("home") : route("welcome")}
						>
							{hasOdessaAfiliateAccount && (
								<OdessaLogo className="h-6 w-auto" />
							)}
							<ApplicationLogo className="h-6 w-auto" />
							<Text>
								<Strong className="!font-poppins">
									Famedic
								</Strong>
							</Text>
						</NavbarItem>
						{auth.user && (
							<NavbarSection>
								<UserNavigationDropdown
									dropdownButtonProps={{
										as: NavbarItem,
										dusk: "userNavigation",
									}}
									dropdownMenuProps={{
										anchor: "bottom end",
										className: "min-w-64",
									}}
								/>
							</NavbarSection>
						)}
					</Navbar>
				</header>

				<div className="px-4">
					<main className="pt-6">{children}</main>
				</div>
			</div>
			<Notification />
			{!hideHelpBubble && <HelpBubble className="bottom-3 right-3" />}
		</div>
	);
}

import ApplicationLogo from "@/Components/ApplicationLogo";
import UserNavigationDropdown from "@/Components/UserNavigationDropdown";
import Notification from "@/Components/Notification";
import { Head, usePage } from "@inertiajs/react";
import { Text, Strong } from "@/Components/Catalyst/text";
import {
	Navbar,
	NavbarItem,
	NavbarSection,
} from "@/Components/Catalyst/navbar";
import OdessaLogo from "@/Components/OdessaLogo";
import HelpBubble from "@/Components/Catalyst/HelpBubble";
import useTrackingEvents from "@/Hooks/useTrackingEvents";
