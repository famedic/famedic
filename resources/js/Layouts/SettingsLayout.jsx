import FamedicLayout from "@/Layouts/FamedicLayout";
import SideBar from "@/Layouts/FamedicLayout/SideBar";
import NavBar from "@/Layouts/FamedicLayout/NavBar";
import VerticalNavbar from "@/Components/VerticalNavbar";
import { usePage } from "@inertiajs/react";
import {
	ShoppingBagIcon,
	DocumentCheckIcon,
	BeakerIcon,
	CreditCardIcon,
	DocumentTextIcon,
	MapPinIcon,
	UserCircleIcon,
	IdentificationIcon,
	CommandLineIcon,
	UsersIcon,
} from "@heroicons/react/20/solid";
import { BuildingLibraryIcon } from "@heroicons/react/16/solid";

const iconMap = {
	UserCircleIcon: UserCircleIcon,
	MapPinIcon: MapPinIcon,
	CreditCardIcon: CreditCardIcon,
	DocumentTextIcon: DocumentTextIcon,
	ShoppingBagIcon: ShoppingBagIcon,
	DocumentCheckIcon: DocumentCheckIcon,
	BeakerIcon: BeakerIcon,
	IdentificationIcon: IdentificationIcon,
	CommandLineIcon: CommandLineIcon,
	UsersIcon: UsersIcon,
	BuildingLibraryIcon: BuildingLibraryIcon,
};

export default function SettingsLayout({ title, children }) {
	const { userNavigation } = usePage().props;

	const navigationMap = userNavigation.map((link) => ({
		...link,
		IconComponent: iconMap[link.icon],
	}));

	return (
		<>
			<FamedicLayout
				title={title}
				navbar={<NavBar />}
				sidebar={<SideBar />}
				reserveMobileBottomNavSpace
			>
				<div className="flex min-w-0 max-w-full flex-col gap-x-8 gap-y-6 lg:flex-row lg:gap-x-10">
					<VerticalNavbar
						links={navigationMap}
						enableMobileBottomNav
						className="top-0 w-auto sm:w-min lg:sticky lg:top-[6.5rem]"
					/>

					<main className="min-w-0 max-w-full flex-auto space-y-6 overflow-x-clip lg:space-y-8">
						{children}
					</main>
				</div>
			</FamedicLayout>
		</>
	);
}
