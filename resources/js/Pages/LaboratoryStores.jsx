export default function LaboratoryStores({
	laboratoryStores,
	laboratoryBrands,
	states,
}) {
	const brandParam =
		new URLSearchParams(window.location.search).get("brand") || "";
	const stateParam =
		new URLSearchParams(window.location.search).get("state") || "";

	const updateSearch = (newBrand, newState) => {
		const params = {
			...(newBrand && { brand: newBrand }),
			...(newState && { state: newState }),
		};

		router.get(route("laboratory-stores.index", { ...params }));
	};

	return (
		<FamedicLayout title="Sucursales de laboratorio">
			<div className="space-y-4">
				<GradientHeading>Sucursales de laboratorio</GradientHeading>
				<Text className="max-w-3xl">
					Aquí puedes revisar el listado de sucursales donde puedes
					agendar la aplicación de tus pruebas. Te invitamos a
					localizar el que más te convenga.
				</Text>
			</div>
			<div className="flex max-w-5xl flex-col gap-4 sm:grid sm:grid-cols-2 sm:flex-row md:grid-cols-3">
				<Field>
					<Label>Filtrar por marca</Label>
					<Listbox
						placeholder="Marca"
						value={brandParam}
						onChange={(newBrand) =>
							updateSearch(newBrand, stateParam)
						}
					>
						<ListboxOption value="">
							<ListboxLabel>Todas las marcas</ListboxLabel>
						</ListboxOption>
						{Object.entries(laboratoryBrands).map(
							([key, brand]) => (
								<ListboxOption key={key} value={key}>
									<div className="flex w-full items-center justify-between gap-2">
										<ListboxLabel>
											{brand.name}
										</ListboxLabel>
										<img
											src={`/images/gda/${brand.imageSrc}`}
											className="h-6 rounded object-cover dark:bg-zinc-200"
										/>
									</div>
								</ListboxOption>
							),
						)}
					</Listbox>
				</Field>
				<Field>
					<Label>Filtrar por estado</Label>
					<Listbox
						placeholder="Estado"
						value={stateParam}
						onChange={(newState) =>
							updateSearch(brandParam, newState)
						}
					>
						<ListboxOption value="">
							<ListboxLabel>Todos los estados</ListboxLabel>
						</ListboxOption>
						{states.map((state) => (
							<ListboxOption key={state} value={state}>
								<ListboxLabel>{state}</ListboxLabel>
							</ListboxOption>
						))}
					</Listbox>
				</Field>
			</div>
			{laboratoryStores.length > 0 ? (
				<Table striped className="[--gutter:theme(spacing.4)]">
					<TableHead>
						<TableRow className="text-famedic-dark dark:text-white">
							<TableHeader className="text-center">
								Marca
							</TableHeader>
							<TableHeader>Sucursal</TableHeader>
							<TableHeader>Lunes a Viernes</TableHeader>
							<TableHeader>Sábado</TableHeader>
							<TableHeader>Domingo</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{laboratoryStores.map((laboratoryStore) => (
							<TableRow key={laboratoryStore.id}>
								<TableCell className="min-w-32 max-w-32 space-y-3 text-center">
									<LaboratoryBrandCard
										src={
											"/images/gda/GDA-" +
											laboratoryStore.brand.toUpperCase() +
											".png"
										}
									/>

									<Badge color="famedic-lime">
										{laboratoryStore.state}
									</Badge>
								</TableCell>
								<TableCell className="flex flex-col text-zinc-500">
									<Subheading>
										{laboratoryStore.name}
									</Subheading>
									<p className="block min-w-64 max-w-64 truncate text-wrap">
										{laboratoryStore.address}
									</p>
									<a
										target="_blank"
										href={laboratoryStore.google_maps_url}
										className="mt-2"
									>
										<Button outline>
											<MapPinIcon />
											Ver en mapa
										</Button>
									</a>
								</TableCell>
								<TableCell className="text-zinc-500">
									{laboratoryStore.weekly_hours}
								</TableCell>
								<TableCell className="text-zinc-500">
									{laboratoryStore.saturday_hours}
								</TableCell>
								<TableCell className="text-zinc-500">
									{laboratoryStore.sunday_hours}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			) : (
				<EmptyListCard />
			)}
		</FamedicLayout>
	);
}

import EmptyListCard from "@/Components/EmptyListCard";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { router } from "@inertiajs/react";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { MapPinIcon } from "@heroicons/react/16/solid";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Button } from "@/Components/Catalyst/button";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
