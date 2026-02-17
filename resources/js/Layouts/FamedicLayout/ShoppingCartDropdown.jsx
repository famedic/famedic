export default function ShoppingCartDropdown({
	selectedIndex,
	setSelectedIndex,
	selectedLaboratoryBrand,
	setSelectedLaboratoryBrand,
	...props
}) {
	const { laboratoryCarts, onlinePharmacyCart, laboratoryBrands } =
		usePage().props;

	return (
		<DropdownMenu {...props}>
			<TabGroup
				selectedIndex={selectedIndex}
				onChange={setSelectedIndex}
				className="col-span-full col-start-1"
			>
				<TabList className="flex justify-start gap-1 px-3.5 py-2.5 sm:px-3 sm:py-1.5">
					<Tab as={Fragment}>
						{({ selected }) => (
							<Button outline={!selected}>Laboratorios</Button>
						)}
					</Tab>

					{/* 
						<Tab as={Fragment}>
							{({ selected }) => (
								<Button outline={!selected}>Farmacia</Button>
							)}
						</Tab>
					*/}

				</TabList>

				<DropdownDivider />

				<TabPanels className="max-w-[18rem]">
					<TabPanel>
						<LaboratoryCartPanel
							laboratoryCarts={laboratoryCarts}
							laboratoryBrands={laboratoryBrands}
							selectedLaboratoryBrand={selectedLaboratoryBrand}
							setSelectedLaboratoryBrand={
								setSelectedLaboratoryBrand
							}
						/>
					</TabPanel>
					<TabPanel>
						<OnlinePharmactCartPanel
							onlinePharmacyCart={onlinePharmacyCart}
						/>
					</TabPanel>
				</TabPanels>
			</TabGroup>
		</DropdownMenu>
	);
}

function LaboratoryCartPanel({
	laboratoryCarts,
	laboratoryBrands,
	selectedLaboratoryBrand,
	setSelectedLaboratoryBrand,
}) {
	return (
		<>
			<div className="px-3.5 py-2.5 sm:px-3 sm:py-1.5">
				<Field>
					<select
						className="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white"
						value={selectedLaboratoryBrand}
						onChange={(e) =>
							setSelectedLaboratoryBrand(e.target.value)
						}
						onMouseDown={(e) => e.stopPropagation()}
						onClick={(e) => e.stopPropagation()}
					>
						{Object.entries(laboratoryBrands).map(
							([value, laboratoryBrand]) => (
								<option key={value} value={value}>
									{laboratoryBrand.name}
								</option>
							),
						)}
					</select>
				</Field>
			</div>

			{laboratoryCarts[selectedLaboratoryBrand]?.length > 0 ? (
				laboratoryCarts[selectedLaboratoryBrand].map((cartItem) => (
					<DropdownItem
						key={cartItem.id}
						className="pointer-events-none flex w-full"
					>
						<div className="flex gap-3 truncate">
							<span className="block truncate text-left">
								<DropdownLabel className="truncate">
									{cartItem.laboratory_test.name}
								</DropdownLabel>
								<DropdownDescription>
									<span className="font-medium text-famedic-light">
										{
											cartItem.laboratory_test
												.formatted_famedic_price
										}
									</span>
								</DropdownDescription>
							</span>
						</div>
					</DropdownItem>
				))
			) : (
				<DropdownItem className="pointer-events-none flex w-full">
					<DropdownLabel>No hay estudios en el carrito</DropdownLabel>
				</DropdownItem>
			)}
			<DropdownDivider />
			<div className="col-span-full px-3.5 py-2.5 sm:px-3 sm:py-1.5">
				<Button
					className="w-full"
					href={route(
						laboratoryCarts[selectedLaboratoryBrand]?.length > 0
							? "laboratory.shopping-cart"
							: "laboratory-tests",
						{
							laboratory_brand: selectedLaboratoryBrand,
						},
					)}
					dusk="laboratoryShoppingCart"
				>
					{laboratoryCarts[selectedLaboratoryBrand]?.length > 0 ? (
						<>
							<ShoppingCartIcon />
							Ir al carrito
						</>
					) : (
						<>
							<BuildingStorefrontIcon />
							Explorar laboratorio
						</>
					)}
				</Button>
			</div>
		</>
	);
}

function OnlinePharmactCartPanel({ onlinePharmacyCart }) {
	const formattedPrice = (price) => {
		return new Intl.NumberFormat("en-US", {
			style: "currency",
			currency: "USD",
		}).format(Number(price));
	};

	return (
		<>
			{onlinePharmacyCart.length > 0 ? (
				onlinePharmacyCart.map((cartItem) => (
					<DropdownItem
						key={cartItem.vitau_product.id}
						className="pointer-events-none flex w-full"
					>
						<div className="grid grid-cols-8 gap-3">
							{cartItem.vitau_product.default_image ? (
								<img
									className="col-span-2 h-full w-full rounded-lg"
									src={cartItem.vitau_product.default_image}
									alt={cartItem.vitau_product.base.name}
								/>
							) : (
								<div className="col-span-2 flex h-full w-full items-center justify-center rounded-lg bg-white dark:bg-slate-800">
									<PhotoIcon className="size-10 fill-zinc-300 dark:fill-slate-600" />
								</div>
							)}

							<span className="col-span-6 truncate text-left">
								<DropdownLabel className="truncate">
									<Badge color="slate">
										{cartItem.quantity}
									</Badge>{" "}
									{cartItem.vitau_product.base.name}
								</DropdownLabel>
								<DropdownDescription className="truncate">
									{cartItem.vitau_product.presentation}
								</DropdownDescription>
								<DropdownDescription className="truncate">
									<span className="font-medium text-famedic-light">
										{formattedPrice(
											cartItem.vitau_product.price,
										)}{" "}
										MXN
									</span>
								</DropdownDescription>
							</span>
						</div>
					</DropdownItem>
				))
			) : (
				<DropdownItem className="pointer-events-none flex w-full">
					<DropdownLabel>
						No hay productos en el carrito
					</DropdownLabel>
				</DropdownItem>
			)}
			<DropdownDivider />
			<div className="col-span-full px-3.5 py-2.5 sm:px-3 sm:py-1.5">
				<Button
					className="w-full"
					href={route(
						onlinePharmacyCart?.length > 0
							? "online-pharmacy.shopping-cart"
							: "online-pharmacy",
					)}
					dusk="onlinePharmacyShoppingCart"
				>
					{onlinePharmacyCart?.length > 0 ? (
						<>
							<ShoppingCartIcon />
							Ir al carrito
						</>
					) : (
						<>
							<BuildingStorefrontIcon />
							Explorar farmacia
						</>
					)}
				</Button>
			</div>
		</>
	);
}

import {
	DropdownDescription,
	DropdownDivider,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { Field } from "@/Components/Catalyst/fieldset";
// Listbox removed in favor of native select due to menu closing issue
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import { usePage } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	BuildingStorefrontIcon,
	ShoppingCartIcon,
} from "@heroicons/react/16/solid";
import { PhotoIcon } from "@heroicons/react/24/solid";
import { Fragment, useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
