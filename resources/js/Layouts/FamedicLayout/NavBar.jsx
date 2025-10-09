import { usePage } from "@inertiajs/react";
import { useState } from "react";
import { Dropdown, DropdownButton } from "@/Components/Catalyst/dropdown";
import {
	Navbar,
	NavbarDivider,
	NavbarItem,
	NavbarSection,
	NavbarSpacer,
} from "@/Components/Catalyst/navbar";
import { ShoppingCartIcon } from "@heroicons/react/20/solid";
import ApplicationLogo from "@/Components/ApplicationLogo";
import { Text, Strong } from "@/Components/Catalyst/text";
import UserNavigationDropdown from "@/Components/UserNavigationDropdown";
import ShoppingCartDropdown from "@/Layouts/FamedicLayout/ShoppingCartDropdown";
import OdessaLogo from "@/Components/OdessaLogo";
import { ArrowRightEndOnRectangleIcon } from "@heroicons/react/24/solid";

export default function NavBar() {
	const {
		auth,
		mainNavigation,
		laboratoryCarts,
		onlinePharmacyCart,
		laboratoryBrand,
		hasOdessaAfiliateAccount,
	} = usePage().props;

	const { user } = auth;
	const homeRoute = auth.user ? route("home") : route("welcome");

	// Determine default tab (laboratory vs pharmacy) based on current route
	const isLaboratoryRoute =
		route().current("laboratory.shopping-cart") ||
		route().current("laboratory-tests") ||
		route().current("laboratory-brand-selection");

	const [selectedIndex, setSelectedIndex] = useState(
		isLaboratoryRoute ? 0 : 1,
	);

	const laboratoryCartKeys = Object.keys(laboratoryCarts || {});
	const firstBrandWithItems = laboratoryCartKeys.find(
		(brand) => (laboratoryCarts?.[brand]?.length || 0) > 0,
	);
	const [selectedLaboratoryBrand, setSelectedLaboratoryBrand] = useState(
		laboratoryBrand
			? laboratoryBrand.value
			: firstBrandWithItems || laboratoryCartKeys[0],
	);

	const laboratoryItemCount =
		(laboratoryCarts &&
			selectedLaboratoryBrand &&
			(laboratoryCarts[selectedLaboratoryBrand]?.length || 0)) ||
		0;
	const pharmacyItemCount = onlinePharmacyCart?.length || 0;
	const cartCount =
		selectedIndex === 0 ? laboratoryItemCount : pharmacyItemCount;

	return (
		<Navbar>
			<NavbarItem href={homeRoute}>
				{hasOdessaAfiliateAccount && (
					<OdessaLogo className="-mr-2 h-6 w-auto" />
				)}
				<ApplicationLogo className="h-6 w-auto" />
				<Text className="-ml-1">
					<Strong className="!font-poppins">Famedic</Strong>
				</Text>
			</NavbarItem>
			<NavbarDivider className="!bg-slate-400 max-lg:hidden dark:!bg-slate-500" />
			<NavbarSection className="max-lg:hidden">
				{mainNavigation.map(({ label, url, current }) => (
					<NavbarItem current={current} key={label} href={url}>
						{label}
					</NavbarItem>
				))}
			</NavbarSection>
			<NavbarSpacer />
			<NavbarSection>
				{user ? (
					<>
						<Dropdown>
							<DropdownButton dusk="shoppingBag" as={NavbarItem}>
								<ShoppingCartIcon className="!fill-famedic-dark dark:!fill-white" />
								{cartCount > 0 && (
									<span className="absolute -right-[.2rem] -top-[.2rem] flex size-4 items-center justify-center rounded-full bg-famedic-lime text-xs font-semibold text-famedic-darker">
										{cartCount}
									</span>
								)}
							</DropdownButton>
							<ShoppingCartDropdown
								anchor="bottom end"
								selectedIndex={selectedIndex}
								setSelectedIndex={setSelectedIndex}
								selectedLaboratoryBrand={
									selectedLaboratoryBrand
								}
								setSelectedLaboratoryBrand={
									setSelectedLaboratoryBrand
								}
							/>
						</Dropdown>

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
					</>
				) : (
					<>
						<NavbarItem href={route("login")}>
							Ingresar
							<ArrowRightEndOnRectangleIcon />
						</NavbarItem>
					</>
				)}
			</NavbarSection>
		</Navbar>
	);
}
